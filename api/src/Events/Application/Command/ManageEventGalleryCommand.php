<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

use App\Events\Application\Service\AdminEventDrafts;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;

final readonly class ManageEventGalleryCommand
{
    private const int MAX_GALLERY_SIZE = 12;

    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private MinioStorageInterface $minioStorage,
        private AdminEventDrafts $adminEventDrafts,
        private PublicMediaUrlResolver $publicMedia,
    ) {
    }

    /**
     * @return array<string, mixed>|null the admin event payload
     *
     * @throws NotFoundException           when the event does not exist
     * @throws ValidationException         when the gallery is already full
     * @throws ServiceUnavailableException when the object storage upload fails
     */
    public function upload(string $eventId, string $key, string $contents): ?array
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            throw new NotFoundException('Événement introuvable.');
        }

        if ($event->getPhotoGalleryCount() >= self::MAX_GALLERY_SIZE) {
            throw new ValidationException('La galerie est pleine (max 12 photos).', [], 'gallery_full');
        }

        try {
            $this->minioStorage->upload($this->publicMedia->bucket(), $key, $contents);
        } catch (\Throwable) {
            throw new ServiceUnavailableException('Le stockage est indisponible.', 'storage_unavailable');
        }

        $event->appendGalleryUpload($key);
        $this->eventRepository->save($event);

        return $this->adminEventDrafts->get($eventId);
    }

    /**
     * @throws NotFoundException when the event does not exist or the gallery index is invalid
     */
    public function delete(string $eventId, int $index): void
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            throw new NotFoundException('Événement introuvable.');
        }

        if (!$event->removeGalleryItem($index)) {
            throw new NotFoundException('Index de galerie invalide.');
        }

        $this->eventRepository->save($event);
    }
}
