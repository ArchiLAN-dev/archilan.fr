<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\Shared\Application\EntityFinderTrait;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ManageEventGalleryCommand
{
    use EntityFinderTrait;

    private const int MAX_GALLERY_SIZE = 12;

    public function __construct(
        private EntityManagerInterface $entityManager,
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
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
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
        $this->entityManager->flush();

        return ['outcome' => 'ok', 'data' => $this->adminEventDrafts->get($eventId)];
    }

    /**
     * @return array{outcome: 'not_found'|'invalid_index'|'ok'}
     */
    public function delete(string $eventId, int $index): array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return ['outcome' => 'not_found'];
        }

        if (!$event->removeGalleryItem($index)) {
            return ['outcome' => 'invalid_index'];
        }

        $this->entityManager->flush();

        return ['outcome' => 'ok'];
    }
}
