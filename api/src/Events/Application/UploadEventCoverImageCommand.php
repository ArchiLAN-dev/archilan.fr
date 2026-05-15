<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\Shared\Application\EntityFinderTrait;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UploadEventCoverImageCommand
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
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
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return ['outcome' => 'not_found', 'data' => null];
        }

        try {
            $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);
        } catch (\Throwable) {
            return ['outcome' => 'storage_error', 'data' => null];
        }

        $event->setCoverImageKey($key, new \DateTimeImmutable());
        $this->entityManager->flush();

        return ['outcome' => 'ok', 'data' => $this->adminEventDrafts->get($eventId)];
    }
}
