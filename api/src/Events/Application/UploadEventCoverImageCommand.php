<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\EventRepositoryInterface;
use App\Shared\Infrastructure\MinioStorageInterface;

final readonly class UploadEventCoverImageCommand
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private MinioStorageInterface $minioStorage,
        private AdminEventDrafts $adminEventDrafts,
        private string $minioMediaBucket,
    ) {
    }

    /**
     * @return array{outcome: 'not_found'|'storage_error'|'ok', data: array<string, mixed>|null}
     */
    public function execute(string $eventId, string $key, string $contents): array
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            return ['outcome' => 'not_found', 'data' => null];
        }

        try {
            $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);
        } catch (\Throwable) {
            return ['outcome' => 'storage_error', 'data' => null];
        }

        $event->setCoverImageKey($key, new \DateTimeImmutable());
        $this->eventRepository->save($event);

        return ['outcome' => 'ok', 'data' => $this->adminEventDrafts->get($eventId)];
    }
}
