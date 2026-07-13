<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRecapRepositoryInterface;
use App\Sessions\Domain\SessionRepositoryInterface;

/**
 * Public read facade for a finished session's recap.
 *
 * Composes the persisted exchange-graph projection with the live podium
 * (RunResultsQuery, reused - handles ranking/released/invalidated) and the
 * event VOD. Returns null - i.e. the controller 404s - when the session is not
 * finished, its event is not a public event (personal/weekly runs never expose
 * a recap), or no projection has been built yet.
 */
final readonly class SessionRecapQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private EventRepositoryInterface $events,
        private SessionRecapRepositoryInterface $recaps,
        private RunResultsQuery $runResults,
    ) {
    }

    /**
     * @return array{
     *     sessionId: string,
     *     eventName: string,
     *     startedAt: string|null,
     *     finishedAt: string|null,
     *     durationSeconds: int|null,
     *     vodUrl: string|null,
     *     generatedAt: string,
     *     podium: list<array<string, mixed>>,
     *     graph: array{
     *         nodes: list<array{slotId: string, slotName: string, game: string}>,
     *         edges: list<array{fromSlotId: string, toSlotId: string, count: int}>,
     *         localItems: list<array{slotId: string, count: int}>
     *     },
     *     superlatives: list<array{key: string, label: string, slotId: string, value: int|string}>
     * }|null
     */
    public function execute(string $sessionId): ?array
    {
        $session = $this->sessions->findById($sessionId);
        if (null === $session || Session::STATUS_FINISHED !== $session->getStatus()) {
            return null;
        }

        // Recaps exist only for public events - never for personal or weekly runs.
        $event = $this->events->findById($session->getEventId());
        if (null === $event || !$event->isPublic()) {
            return null;
        }

        $recap = $this->recaps->findBySessionId($sessionId);
        if (null === $recap) {
            return null;
        }

        $podium = $this->runResults->execute($sessionId);
        if (null === $podium) {
            return null;
        }

        return [
            'sessionId' => $sessionId,
            'eventName' => $podium['eventName'],
            'startedAt' => $podium['startedAt'],
            'finishedAt' => $podium['finishedAt'],
            'durationSeconds' => $podium['durationSeconds'],
            'vodUrl' => $event->getVodUrl(),
            'generatedAt' => $recap->getGeneratedAt()->format(\DateTimeInterface::ATOM),
            'podium' => $podium['slots'],
            'graph' => [
                'nodes' => $recap->getNodes(),
                'edges' => $recap->getEdges(),
                'localItems' => $recap->getLocalItems(),
            ],
            'superlatives' => $recap->getSuperlatives(),
        ];
    }
}
