<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

use App\Events\Application\Service\AdminEventDrafts;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;

final readonly class UploadEventCoverImageCommand
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private MinioStorageInterface $minioStorage,
        private AdminEventDrafts $adminEventDrafts,
        private ClockInterface $clock,
        private PublicMediaUrlResolver $publicMedia,
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
            $this->minioStorage->upload($this->publicMedia->bucket(), $key, $contents);
        } catch (\Throwable) {
            return ['outcome' => 'storage_error', 'data' => null];
        }

        $event->attachCoverImage($key, $this->clock->now());
        $this->eventRepository->save($event);

        return ['outcome' => 'ok', 'data' => $this->adminEventDrafts->get($eventId)];
    }
}
