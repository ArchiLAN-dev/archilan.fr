<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\Payments\Application\HelloAssoConfig;
use App\Registrations\Application\RegistrationCounter;
use App\Shared\Application\EntityFinderTrait;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PublicEventCatalog
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RegistrationCounter $registrationCounter,
        private HelloAssoConfig $helloAssoConfig,
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
        private int $minioPresignTtl,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, hasPrivateAccessPassword: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, checkoutEmbedUrl: string|null, checkoutUnavailable: bool}>
     */
    public function list(): array
    {
        /** @var list<Event> $events */
        $events = $this->entityManager->getRepository(Event::class)->findBy(['status' => Event::PUBLIC_STATUSES], ['startsAt' => 'ASC'], 500);

        return array_map(fn (Event $event): array => [
            ...$this->payload($event),
        ], $events);
    }

    /**
     * @return array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, hasPrivateAccessPassword: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, checkoutEmbedUrl: string|null, checkoutUnavailable: bool}|null
     */
    public function get(string $eventId): ?array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        if (!$event->isVisiblePublicly()) {
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
            'coverImageUrl' => $this->resolveCoverImageUrl($event),
            'photoGallery' => $this->resolvePhotoGallery($event),
            'checkoutEmbedUrl' => $checkoutEmbedUrl,
            'checkoutUnavailable' => $this->isCheckoutUnavailable($event, $confirmedCount, $checkoutEmbedUrl),
        ];
    }

    private function resolveCoverImageUrl(Event $event): ?string
    {
        $key = $event->getCoverImageKey();
        if (null !== $key) {
            return $this->minioStorage->presignedUrl($this->minioMediaBucket, $key, $this->minioPresignTtl);
        }

        return $event->getCoverImageUrl();
    }

    /**
     * @return list<string>
     */
    private function resolvePhotoGallery(Event $event): array
    {
        $result = [];
        foreach ($event->getPhotoGallery() as $item) {
            if ('upload' === $item['source']) {
                $result[] = $this->minioStorage->presignedUrl($this->minioMediaBucket, $item['key'] ?? '', $this->minioPresignTtl);
            } else {
                $result[] = $item['url'] ?? '';
            }
        }

        return $result;
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
