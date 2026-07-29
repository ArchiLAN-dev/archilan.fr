<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

use App\Events\Application\Query\AdminEventView;
use App\Events\Application\Service\AdminEventDrafts;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class UploadEventCoverImageCommand
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private MinioStorageInterface $minioStorage,
        private AdminEventDrafts $adminEventDrafts,
        private ClockInterface $clock,
        private PublicMediaUrlResolver $publicMedia,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws NotFoundException           when the event does not exist
     * @throws ServiceUnavailableException when the object storage upload fails
     */
    public function execute(string $eventId, string $key, string $contents): ?AdminEventView
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            throw new NotFoundException('Événement introuvable.');
        }

        try {
            $this->minioStorage->upload($this->publicMedia->bucket(), $key, $contents);
        } catch (\Throwable $exception) {
            // The client only ever sees a generic storage_unavailable; log the real cause here or it is
            // lost. A missing/misconfigured public-media bucket surfaces exactly this way.
            $this->logger->error('Object storage upload failed for an event cover image.', [
                'bucket' => $this->publicMedia->bucket(),
                'key' => $key,
                'exception' => $exception,
            ]);

            throw new ServiceUnavailableException('Le stockage est indisponible.', 'storage_unavailable');
        }

        $event->attachCoverImage($key, $this->clock->now());
        $this->eventRepository->save($event);

        return $this->adminEventDrafts->get($eventId);
    }
}
