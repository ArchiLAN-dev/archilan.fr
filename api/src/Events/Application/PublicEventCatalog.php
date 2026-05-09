<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\Payments\Application\HelloAssoConfig;
use App\Registrations\Application\RegistrationCounter;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicEventCatalog
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RegistrationCounter $registrationCounter,
        private HelloAssoConfig $helloAssoConfig,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, hasPrivateAccessPassword: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, checkoutEmbedUrl: string|null, checkoutUnavailable: bool}>
     */
    public function list(): array
    {
        /** @var list<Event> $events */
        $events = $this->entityManager->createQueryBuilder()
            ->select('event')
            ->from(Event::class, 'event')
            ->andWhere('event.status IN (:statuses)')
            ->setParameter('statuses', Event::PUBLIC_STATUSES)
            ->orderBy('event.startsAt', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return array_map(fn (Event $event): array => [
            ...$this->payload($event),
        ], $events);
    }

    /**
     * @return array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, hasPrivateAccessPassword: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, checkoutEmbedUrl: string|null, checkoutUnavailable: bool}|null
     */
    public function get(string $eventId): ?array
    {
        $event = $this->entityManager->find(Event::class, $eventId);

        if (!$event instanceof Event || !$event->isVisiblePublicly()) {
            return null;
        }

        return $this->payload($event);
    }

    /**
     * @return array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, hasPrivateAccessPassword: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, checkoutEmbedUrl: string|null, checkoutUnavailable: bool}
     */
    private function payload(Event $event): array
    {
        $confirmedCount = $this->registrationCounter->countConfirmed($event->getId());
        $checkoutEmbedUrl = $this->buildCheckoutEmbedUrl($event, $confirmedCount);

        return [
            'id' => $event->getId(),
            'title' => $event->getTitle(),
            'description' => $event->getDescription(),
            'status' => $event->getStatus(),
            'startsAt' => $event->getStartsAt()->format(\DateTimeInterface::ATOM),
            'endsAt' => $event->getEndsAt()->format(\DateTimeInterface::ATOM),
            'venue' => $event->getVenue(),
            'capacity' => $event->getCapacity(),
            'confirmedRegistrations' => $confirmedCount,
            'registrationOpensAt' => $event->getRegistrationOpensAt()->format(\DateTimeInterface::ATOM),
            'registrationClosesAt' => $event->getRegistrationClosesAt()->format(\DateTimeInterface::ATOM),
            'isPublic' => $event->isPublic(),
            'hasPrivateAccessPassword' => $event->hasPrivateAccessPassword(),
            'vodUrl' => $event->getVodUrl(),
            'recapPostSlug' => $event->getRecapPostSlug(),
            'hasRecap' => $event->hasRecap(),
            'coverImageUrl' => $event->getCoverImageUrl(),
            'photoGallery' => $event->getPhotoGallery(),
            'checkoutEmbedUrl' => $checkoutEmbedUrl,
            'checkoutUnavailable' => $this->isCheckoutUnavailable($event, $confirmedCount, $checkoutEmbedUrl),
        ];
    }

    private function buildCheckoutEmbedUrl(Event $event, int $confirmedCount): ?string
    {
        if (!$this->canExposeCheckout($event, $confirmedCount)) {
            return null;
        }

        $formSlug = $event->getHelloassoFormSlug();

        if (null === $formSlug) {
            return null;
        }

        try {
            return $this->helloAssoConfig->buildEmbedUrl(HelloAssoConfig::FORM_TYPE_EVENT, $formSlug);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function isCheckoutUnavailable(Event $event, int $confirmedCount, ?string $checkoutEmbedUrl): bool
    {
        if (!$this->canExposeCheckout($event, $confirmedCount) || null === $event->getHelloassoFormSlug()) {
            return false;
        }

        return null === $checkoutEmbedUrl;
    }

    private function canExposeCheckout(Event $event, int $confirmedCount): bool
    {
        $now = new \DateTimeImmutable();

        return $event->isPublic()
            && Event::STATUS_PUBLISHED === $event->getStatus()
            && !$event->isAtCapacity($confirmedCount)
            && $event->getRegistrationOpensAt() <= $now
            && $event->getRegistrationClosesAt() >= $now;
    }
}
