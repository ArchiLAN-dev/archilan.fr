<?php

declare(strict_types=1);

namespace App\Events\Application\Service;

use App\Events\Application\Query\AdminEventView;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Events\Presentation\Controller\AdminEventGalleryController;
use App\Identity\Application\Support\ValidationErrors;
use App\Registrations\Application\Query\RegistrationCounter;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminEventDrafts
{
    /** Mirrors EVENT_DESCRIPTION_MAX in the frontend's content-limits.ts. */
    private const int MAX_DESCRIPTION = 5000;

    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private RegistrationCounter $registrationCounter,
        private LoggerInterface $logger,
        private PublicMediaUrlResolver $publicMedia,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<AdminEventView>
     */
    public function list(): array
    {
        $events = $this->eventRepository->findAllSortedByStartsAt();

        return array_map($this->payload(...), $events);
    }

    public function get(string $eventId): ?AdminEventView
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event instanceof Event) {
            return null;
        }

        return $this->payload($event);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{event?: AdminEventView, errors: array<string, list<string>>}
     */
    public function create(array $input): array
    {
        $parsed = $this->parse($input);
        $errors = $this->validate($parsed, 0);

        if ([] !== $errors) {
            return ['errors' => $errors];
        }

        $complete = $this->completeInput($parsed);
        if (null === $complete) {
            return ['errors' => ['body' => ["Le brouillon d'événement est incomplet."]]];
        }

        $event = Event::draft(
            $complete['title'],
            $complete['description'],
            $complete['startsAt'],
            $complete['endsAt'],
            $complete['venue'],
            $complete['capacity'],
            $complete['registrationOpensAt'],
            $complete['registrationClosesAt'],
            $complete['isPublic'],
            $this->clock->now(),
            $parsed['coverImageUrl'],
            $parsed['photoGallery'],
        );

        $event->linkHelloassoForm($parsed['helloassoFormSlug'], $this->clock->now());
        $this->eventRepository->save($event);

        $this->logger->info('event.created', ['eventId' => $event->getId(), 'title' => $event->getTitle()]);

        return ['event' => $this->payload($event), 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, event?: AdminEventView, errors: array<string, list<string>>}
     */
    public function update(string $eventId, array $input): array
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event instanceof Event) {
            return ['found' => false, 'errors' => []];
        }

        $parsed = $this->parse($input);
        $confirmedCount = $this->registrationCounter->countConfirmed($eventId);
        $errors = $this->validate($parsed, $confirmedCount);

        if ([] !== $errors) {
            return ['found' => true, 'errors' => $errors];
        }

        $complete = $this->completeInput($parsed);
        if (null === $complete) {
            return ['found' => true, 'errors' => ['body' => ["L'événement est incomplet."]]];
        }

        $photoGallery = $this->reconcilePhotoGallery($parsed['photoGallery']);

        $event->updateDetails(
            $complete['title'],
            $complete['description'],
            $complete['startsAt'],
            $complete['endsAt'],
            $complete['venue'],
            $complete['capacity'],
            $complete['registrationOpensAt'],
            $complete['registrationClosesAt'],
            $complete['isPublic'],
            $this->clock->now(),
            $parsed['coverImageUrl'],
            $photoGallery,
        );
        if ('url' === $parsed['coverImageMode']) {
            $event->clearCoverImageKey($this->clock->now());
        }
        $event->linkHelloassoForm($parsed['helloassoFormSlug'], $this->clock->now());
        $this->eventRepository->save($event);

        $this->logger->info('event.updated', ['eventId' => $event->getId()]);

        return ['found' => true, 'event' => $this->payload($event), 'errors' => []];
    }

    /**
     * @return array{found: bool, event?: AdminEventView, errors: array<string, list<string>>}
     */
    public function transition(string $eventId, mixed $status): array
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event instanceof Event) {
            return ['found' => false, 'errors' => []];
        }

        if (!is_string($status) || '' === trim($status)) {
            return ['found' => true, 'errors' => ['status' => ['Le statut cible est requis.']]];
        }

        try {
            $event->transitionTo(trim($status), $this->clock->now());
        } catch (\DomainException) {
            return ['found' => true, 'errors' => ['status' => ['Transition de statut invalide.']]];
        }

        $this->eventRepository->save($event);

        $this->logger->info('event.transition', ['eventId' => $event->getId(), 'to' => $event->getStatus()]);

        return ['found' => true, 'event' => $this->payload($event), 'errors' => []];
    }

    /**
     * @return array{found: bool, event?: AdminEventView, errors: array<string, list<string>>}
     */
    public function configurePrivateAccess(string $eventId, mixed $password): array
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event instanceof Event) {
            return ['found' => false, 'errors' => []];
        }

        if ($event->isPublic()) {
            return ['found' => true, 'errors' => ['visibility' => ["L'événement doit être privé avant de configurer un mot de passe."]]];
        }

        if (!is_string($password) || mb_strlen($password) < 8) {
            return ['found' => true, 'errors' => ['password' => ['Le mot de passe doit contenir au moins 8 caractères.']]];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $event->configurePrivateAccessPassword($hash, $this->clock->now());
        $this->eventRepository->save($event);

        $this->logger->info('event.private_access_configured', ['eventId' => $event->getId()]);

        return ['found' => true, 'event' => $this->payload($event), 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{title: string, description: string, startsAt: \DateTimeImmutable|null, endsAt: \DateTimeImmutable|null, venue: string, capacity: int|null, registrationOpensAt: \DateTimeImmutable|null, registrationClosesAt: \DateTimeImmutable|null, isPublic: bool, helloassoFormSlug: string|null, coverImageUrl: string|null, coverImageMode: 'url'|'upload', photoGallery: list<string>}
     */
    private function parse(array $input): array
    {
        $coverImageMode = 'upload' === ($input['coverImageMode'] ?? null) ? 'upload' : 'url';

        return [
            'title' => is_string($input['title'] ?? null) ? trim($input['title']) : '',
            'description' => is_string($input['description'] ?? null) ? trim($input['description']) : '',
            'startsAt' => $this->dateValue($input['startsAt'] ?? null),
            'endsAt' => $this->dateValue($input['endsAt'] ?? null),
            'venue' => is_string($input['venue'] ?? null) ? trim($input['venue']) : '',
            'capacity' => is_int($input['capacity'] ?? null) ? $input['capacity'] : null,
            'registrationOpensAt' => $this->dateValue($input['registrationOpensAt'] ?? null),
            'registrationClosesAt' => $this->dateValue($input['registrationClosesAt'] ?? null),
            'isPublic' => true === ($input['isPublic'] ?? false),
            'helloassoFormSlug' => is_string($input['helloassoFormSlug'] ?? null) && '' !== trim($input['helloassoFormSlug']) ? trim($input['helloassoFormSlug']) : null,
            'coverImageUrl' => is_string($input['coverImageUrl'] ?? null) && '' !== trim($input['coverImageUrl']) ? trim($input['coverImageUrl']) : null,
            'coverImageMode' => $coverImageMode,
            'photoGallery' => $this->parsePhotoGallery($input['photoGallery'] ?? []),
        ];
    }

    /**
     * @param array{title: string, description: string, startsAt: \DateTimeImmutable|null, endsAt: \DateTimeImmutable|null, venue: string, capacity: int|null, registrationOpensAt: \DateTimeImmutable|null, registrationClosesAt: \DateTimeImmutable|null, isPublic: bool, helloassoFormSlug: string|null, coverImageUrl: string|null, coverImageMode: 'url'|'upload', photoGallery: list<string>} $input
     *
     * @return array{title: string, description: string, startsAt: \DateTimeImmutable, endsAt: \DateTimeImmutable, venue: string, capacity: int, registrationOpensAt: \DateTimeImmutable, registrationClosesAt: \DateTimeImmutable, isPublic: bool}|null
     */
    private function completeInput(array $input): ?array
    {
        if (
            !$input['startsAt'] instanceof \DateTimeImmutable
            || !$input['endsAt'] instanceof \DateTimeImmutable
            || !$input['registrationOpensAt'] instanceof \DateTimeImmutable
            || !$input['registrationClosesAt'] instanceof \DateTimeImmutable
            || null === $input['capacity']
        ) {
            return null;
        }

        return [
            'title' => $input['title'],
            'description' => $input['description'],
            'startsAt' => $input['startsAt'],
            'endsAt' => $input['endsAt'],
            'venue' => $input['venue'],
            'capacity' => $input['capacity'],
            'registrationOpensAt' => $input['registrationOpensAt'],
            'registrationClosesAt' => $input['registrationClosesAt'],
            'isPublic' => $input['isPublic'],
        ];
    }

    /**
     * @param array{title: string, description: string, startsAt: \DateTimeImmutable|null, endsAt: \DateTimeImmutable|null, venue: string, capacity: int|null, registrationOpensAt: \DateTimeImmutable|null, registrationClosesAt: \DateTimeImmutable|null, isPublic: bool, helloassoFormSlug: string|null, coverImageUrl: string|null, coverImageMode: 'url'|'upload', photoGallery: list<string>} $input
     *
     * @return array<string, list<string>>
     */
    private function validate(array $input, int $confirmedRegistrations): array
    {
        $errors = new ValidationErrors();

        foreach (['title' => 'Le titre est requis.', 'description' => 'La description est requise.', 'venue' => 'Le lieu est requis.'] as $field => $message) {
            if ('' === $input[$field]) {
                $errors->add($field, $message);
            }
        }

        // Was unbounded TEXT; markdown makes long input more attractive, so cap it (story 10.10).
        if (mb_strlen($input['description']) > self::MAX_DESCRIPTION) {
            $errors->add('description', sprintf('La description ne peut pas dépasser %d caractères.', self::MAX_DESCRIPTION));
        }

        if (null === $input['capacity'] || $input['capacity'] <= 0) {
            $errors->add('capacity', 'La capacité doit être supérieure à 0.');
        } elseif ($input['capacity'] < $confirmedRegistrations) {
            $errors->add('capacity', sprintf('La capacité ne peut pas être inférieure aux %d inscriptions confirmées.', $confirmedRegistrations));
        }

        foreach (['startsAt' => 'La date de début est requise.', 'endsAt' => 'La date de fin est requise.', 'registrationOpensAt' => "La date d'ouverture des inscriptions est requise.", 'registrationClosesAt' => 'La date de fermeture des inscriptions est requise.'] as $field => $message) {
            if (null === $input[$field]) {
                $errors->add($field, $message);
            }
        }

        if ($input['startsAt'] instanceof \DateTimeImmutable && $input['endsAt'] instanceof \DateTimeImmutable && $input['endsAt'] <= $input['startsAt']) {
            $errors->add('endsAt', "La fin de l'événement doit être après son début.");
        }

        if ($input['registrationOpensAt'] instanceof \DateTimeImmutable && $input['registrationClosesAt'] instanceof \DateTimeImmutable && $input['registrationClosesAt'] <= $input['registrationOpensAt']) {
            $errors->add('registrationClosesAt', 'La fermeture des inscriptions doit être après leur ouverture.');
        }

        if ($input['registrationOpensAt'] instanceof \DateTimeImmutable && $input['startsAt'] instanceof \DateTimeImmutable && $input['registrationOpensAt'] >= $input['startsAt']) {
            $errors->add('registrationOpensAt', "L'ouverture des inscriptions doit être avant le début de l'événement.");
        }

        if ($input['registrationClosesAt'] instanceof \DateTimeImmutable && $input['startsAt'] instanceof \DateTimeImmutable && $input['registrationClosesAt'] > $input['startsAt']) {
            $errors->add('registrationClosesAt', "Les inscriptions doivent fermer avant le début de l'événement.");
        }

        if (null !== $input['coverImageUrl']) {
            if (mb_strlen($input['coverImageUrl']) > 2048) {
                $errors->add('coverImageUrl', "L'URL de couverture ne peut pas dépasser 2048 caractères.");
            } elseif (false === filter_var($input['coverImageUrl'], FILTER_VALIDATE_URL)) {
                $errors->add('coverImageUrl', "L'URL de couverture doit être une URL valide.");
            }
        }

        if ([] !== $input['photoGallery']) {
            if (count($input['photoGallery']) < 2) {
                $errors->add('photoGallery', 'La galerie doit contenir au moins 2 photos.');
            }

            if (count($input['photoGallery']) > 12) {
                $errors->add('photoGallery', 'La galerie ne peut pas contenir plus de 12 photos.');
            }

            foreach ($input['photoGallery'] as $url) {
                if (mb_strlen($url) > 2048 || false === filter_var($url, FILTER_VALIDATE_URL)) {
                    $errors->add('photoGallery', 'Chaque photo doit être une URL valide de 2048 caractères maximum.');
                    break;
                }
            }
        }

        return $errors->toArray();
    }

    private function dateValue(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        // Reject relative strings like "tomorrow"; require an absolute ISO 8601 date.
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function payload(Event $event): AdminEventView
    {
        $confirmedCount = $this->registrationCounter->countConfirmed($event->getId());

        return new AdminEventView(
            $event->getId(),
            $event->getTitle(),
            $event->getDescription(),
            $event->getStatus(),
            $event->getStartsAt()->format(\DateTimeInterface::ATOM),
            $event->getEndsAt()->format(\DateTimeInterface::ATOM),
            $event->getVenue(),
            $event->getCapacity(),
            $confirmedCount,
            $event->isAtCapacity($confirmedCount),
            $event->getRegistrationOpensAt()->format(\DateTimeInterface::ATOM),
            $event->getRegistrationClosesAt()->format(\DateTimeInterface::ATOM),
            $event->isPublic(),
            $event->isPublic() ? 'public' : 'private',
            $event->hasPrivateAccessPassword(),
            $event->isGameSelectionEnabled(),
            $event->getVodUrl(),
            $event->getRecapPostSlug(),
            $event->hasRecap(),
            $event->getHelloassoFormSlug(),
            $this->resolveCoverImageUrl($event),
            $event->getCoverImageKey(),
            $this->resolvePhotoGallery($event),
            $event->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $event->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    private function resolveCoverImageUrl(Event $event): ?string
    {
        $key = $event->getCoverImageKey();
        if (null !== $key) {
            return $this->publicMedia->resolve($key);
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
                $result[] = $this->publicMedia->resolve($item['key'] ?? '');
            } else {
                $result[] = $item['url'] ?? '';
            }
        }

        return $result;
    }

    /**
     * Re-key submitted gallery entries so uploaded photos stay re-signable.
     *
     * The admin form round-trips the *presigned* URLs produced by
     * {@see resolvePhotoGallery()}. A presigned URL is non-deterministic - its
     * signature and expiry change on every call - so it must never be matched
     * by string equality nor stored verbatim (it would freeze and expire ~1h
     * later). Any entry that points at an object in our media bucket is stored
     * as an {source: 'upload', key} item and re-signed on every read; genuinely
     * external URLs are kept as plain strings.
     *
     * @param list<string> $submittedUrls
     *
     * @return list<string|array{source: string, key: string}>
     */
    private function reconcilePhotoGallery(array $submittedUrls): array
    {
        $result = [];
        foreach ($submittedUrls as $submittedUrl) {
            $key = $this->extractMediaObjectKey($submittedUrl);
            $result[] = null !== $key
                ? ['source' => 'upload', 'key' => $key]
                : $submittedUrl;
        }

        return $result;
    }

    /**
     * Return the media-bucket object key for a gallery upload URL, or null when
     * the URL is not one of our managed gallery objects.
     *
     * Matches the stable key layout produced by AdminEventGalleryController
     * (events/{eventId}/gallery/{file}) at the end of the URL path, ignoring the
     * presign query string, the host, and whichever prefix precedes it (the media
     * or media-public bucket, or a CDN base with no bucket segment at all).
     */
    private function extractMediaObjectKey(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        if (1 !== preg_match('#(events/[^/]+/gallery/[^/]+)$#', $path, $matches)) {
            return null;
        }

        return rawurldecode($matches[1]);
    }

    /**
     * @return list<string>
     */
    private function parsePhotoGallery(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $urls = [];
        foreach ($value as $url) {
            if (is_string($url) && '' !== trim($url)) {
                $urls[] = trim($url);
            }
        }

        return $urls;
    }
}
