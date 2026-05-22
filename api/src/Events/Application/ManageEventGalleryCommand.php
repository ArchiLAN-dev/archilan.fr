<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\EventRepositoryInterface;
use App\Shared\Infrastructure\MinioStorageInterface;

final readonly class ManageEventGalleryCommand
{
    private const int MAX_GALLERY_SIZE = 12;

    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private MinioStorageInterface $minioStorage,
        private AdminEventDrafts $adminEventDrafts,
        private string $minioMediaBucket,
    ) {
    }

    /**
     * @return array{outcome: 'not_found'|'gallery_full'|'storage_error'|'ok', data: array<string, mixed>|null}
     */
    public function upload(string $eventId, string $key, string $contents): array
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            return ['outcome' => 'not_found', 'data' => null];
        }

        if ($event->getPhotoGalleryCount() >= self::MAX_GALLERY_SIZE) {
            return ['outcome' => 'gallery_full', 'data' => null];
        }

        try {
            $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);
        } catch (\Throwable) {
            return ['outcome' => 'storage_error', 'data' => null];
        }

        $event->appendGalleryUpload($key);
        $this->eventRepository->save($event);

        return ['outcome' => 'ok', 'data' => $this->adminEventDrafts->get($eventId)];
    }

    /**
     * @return array{outcome: 'not_found'|'invalid_index'|'ok'}
     */
    public function delete(string $eventId, int $index): array
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            return ['outcome' => 'not_found'];
        }

        if (!$event->removeGalleryItem($index)) {
            return ['outcome' => 'invalid_index'];
        }

        $this->eventRepository->save($event);

        return ['outcome' => 'ok'];
    }
}
