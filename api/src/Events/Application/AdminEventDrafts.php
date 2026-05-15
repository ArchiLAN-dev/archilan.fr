<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\Identity\Application\ValidationErrors;
use App\Registrations\Application\RegistrationCounter;
use App\Shared\Application\EntityFinderTrait;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminEventDrafts
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RegistrationCounter $registrationCounter,
        private LoggerInterface $logger,
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
        private int $minioPresignTtl,
    ) {
    }

    /**
     * @return list<array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, isAtCapacity: bool, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, visibility: string, hasPrivateAccessPassword: bool, gameSelectionEnabled: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, helloassoFormSlug: string|null, createdAt: string, updatedAt: string}>
     */
    public function list(): array
    {
        /** @var list<Event> $events */
        $events = $this->entityManager->createQueryBuilder()
            ->select('event')
            ->from(Event::class, 'event')
            ->orderBy('event.startsAt', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        return array_map(fn (Event $event): array => $this->payload($event), $events);
    }

    /**
     * @return array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, isAtCapacity: bool, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, visibility: string, hasPrivateAccessPassword: bool, gameSelectionEnabled: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, helloassoFormSlug: string|null, createdAt: string, updatedAt: string}|null
     */
    public function get(string $eventId): ?array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        return $this->payload($event);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{event?: array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, isAtCapacity: bool, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, visibility: string, hasPrivateAccessPassword: bool, gameSelectionEnabled: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, helloassoFormSlug: string|null, createdAt: string, updatedAt: string}, errors: array<string, list<string>>}
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
            new \DateTimeImmutable(),
            $parsed['coverImageUrl'],
            $parsed['photoGallery'],
        );

        $event->setHelloassoFormSlug($parsed['helloassoFormSlug'], new \DateTimeImmutable());
        $this->entityManager->persist($event);
        $this->entityManager->flush();

        $this->logger->info('event.created', ['eventId' => $event->getId(), 'title' => $event->getTitle()]);

        return ['event' => $this->payload($event), 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, event?: array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, isAtCapacity: bool, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, visibility: string, hasPrivateAccessPassword: bool, gameSelectionEnabled: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, helloassoFormSlug: string|null, createdAt: string, updatedAt: string}, errors: array<string, list<string>>}
     */
    public function update(string $eventId, array $input): array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
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

        $photoGallery = $this->reconcilePhotoGallery($event, $parsed['photoGallery']);

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
            new \DateTimeImmutable(),
            $parsed['coverImageUrl'],
            $photoGallery,
        );
        if ('url' === $parsed['coverImageMode']) {
            $event->clearCoverImageKey(new \DateTimeImmutable());
        }
        $event->setHelloassoFormSlug($parsed['helloassoFormSlug'], new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->logger->info('event.updated', ['eventId' => $event->getId()]);

        return ['found' => true, 'event' => $this->payload($event), 'errors' => []];
    }

    /**
     * @return array{found: bool, event?: array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, isAtCapacity: bool, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, visibility: string, hasPrivateAccessPassword: bool, gameSelectionEnabled: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, helloassoFormSlug: string|null, createdAt: string, updatedAt: string}, errors: array<string, list<string>>}
     */
    public function transition(string $eventId, mixed $status): array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return ['found' => false, 'errors' => []];
        }

        if (!is_string($status) || '' === trim($status)) {
            return ['found' => true, 'errors' => ['status' => ['Le statut cible est requis.']]];
        }

        try {
            $event->transitionTo(trim($status), new \DateTimeImmutable());
        } catch (\DomainException) {
            return ['found' => true, 'errors' => ['status' => ['Transition de statut invalide.']]];
        }

        $this->entityManager->flush();

        $this->logger->info('event.transition', ['eventId' => $event->getId(), 'to' => $event->getStatus()]);

        return ['found' => true, 'event' => $this->payload($event), 'errors' => []];
    }

    /**
     * @return array{found: bool, event?: array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, isAtCapacity: bool, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, visibility: string, hasPrivateAccessPassword: bool, gameSelectionEnabled: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, helloassoFormSlug: string|null, createdAt: string, updatedAt: string}, errors: array<string, list<string>>}
     */
    public function configurePrivateAccess(string $eventId, mixed $password): array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return ['found' => false, 'errors' => []];
        }

        if ($event->isPublic()) {
            return ['found' => true, 'errors' => ['visibility' => ["L'événement doit être privé avant de configurer un mot de passe."]]];
        }

        if (!is_string($password) || mb_strlen($password) < 8) {
            return ['found' => true, 'errors' => ['password' => ['Le mot de passe doit contenir au moins 8 caractères.']]];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $event->configurePrivateAccessPassword($hash, new \DateTimeImmutable());
        $this->entityManager->flush();

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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array{id: string, title: string, description: string, status: string, startsAt: string, endsAt: string, venue: string, capacity: int, confirmedRegistrations: int, isAtCapacity: bool, registrationOpensAt: string, registrationClosesAt: string, isPublic: bool, visibility: string, hasPrivateAccessPassword: bool, gameSelectionEnabled: bool, vodUrl: string|null, recapPostSlug: string|null, hasRecap: bool, helloassoFormSlug: string|null, createdAt: string, updatedAt: string}
     */
    private function payload(Event $event): array
    {
        $confirmedCount = $this->registrationCounter->countConfirmed($event->getId());

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
            'isAtCapacity' => $event->isAtCapacity($confirmedCount),
            'registrationOpensAt' => $event->getRegistrationOpensAt()->format(\DateTimeInterface::ATOM),
            'registrationClosesAt' => $event->getRegistrationClosesAt()->format(\DateTimeInterface::ATOM),
            'isPublic' => $event->isPublic(),
            'visibility' => $event->isPublic() ? 'public' : 'private',
            'hasPrivateAccessPassword' => $event->hasPrivateAccessPassword(),
            'gameSelectionEnabled' => $event->isGameSelectionEnabled(),
            'vodUrl' => $event->getVodUrl(),
            'recapPostSlug' => $event->getRecapPostSlug(),
            'hasRecap' => $event->hasRecap(),
            'helloassoFormSlug' => $event->getHelloassoFormSlug(),
            'coverImageUrl' => $this->resolveCoverImageUrl($event),
            'coverImageKey' => $event->getCoverImageKey(),
            'photoGallery' => $this->resolvePhotoGallery($event),
            'createdAt' => $event->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $event->getUpdatedAt()->format(\DateTimeInterface::ATOM),
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

    /**
     * @param list<string> $submittedUrls
     *
     * @return list<string|array{source: string, key: string}>
     */
    private function reconcilePhotoGallery(Event $event, array $submittedUrls): array
    {
        $currentItems = $event->getPhotoGallery();
        $currentUrls = $this->resolvePhotoGallery($event);
        $matchedIndexes = [];
        $result = [];

        foreach ($submittedUrls as $submittedUrl) {
            $preservedUpload = null;
            foreach ($currentUrls as $index => $currentUrl) {
                if (isset($matchedIndexes[$index]) || $currentUrl !== $submittedUrl) {
                    continue;
                }

                $currentItem = $currentItems[$index] ?? null;
                if ('upload' === ($currentItem['source'] ?? null) && isset($currentItem['key'])) {
                    $preservedUpload = ['source' => 'upload', 'key' => $currentItem['key']];
                    $matchedIndexes[$index] = true;
                    break;
                }
            }

            $result[] = $preservedUpload ?? $submittedUrl;
        }

        return $result;
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
