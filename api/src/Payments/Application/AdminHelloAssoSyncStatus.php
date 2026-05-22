<?php

declare(strict_types=1);

namespace App\Payments\Application;

use App\Events\Domain\EventRepositoryInterface;
use App\Payments\Domain\HelloAssoSyncLog;
use App\Payments\Domain\HelloAssoSyncLogRepositoryInterface;

final readonly class AdminHelloAssoSyncStatus
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private HelloAssoSyncLogRepositoryInterface $syncLogRepository,
    ) {
    }

    /**
     * Returns recent sync attempts for an event's HelloAsso form, or null if the event is not found.
     *
     * @return array{formSlug: string|null, recentSyncs: list<array{attemptAt: string, success: bool, errorMessage: string|null}>}|null
     */
    public function getForEvent(string $eventId): ?array
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            return null;
        }

        $formSlug = $event->getHelloassoFormSlug();

        if (null === $formSlug) {
            return ['formSlug' => null, 'recentSyncs' => []];
        }

        $logs = $this->syncLogRepository->findRecentByFormSlug($formSlug, 10);

        $recentSyncs = array_map(
            static fn (HelloAssoSyncLog $log): array => [
                'attemptAt' => $log->getAttemptAt()->format(\DateTimeInterface::ATOM),
                'success' => $log->isSuccess(),
                'errorMessage' => $log->getErrorMessage(),
            ],
            $logs,
        );

        return ['formSlug' => $formSlug, 'recentSyncs' => $recentSyncs];
    }
}
