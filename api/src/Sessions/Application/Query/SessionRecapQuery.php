<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Support\SessionRecapAudience;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRecapRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * Read facade for a finished session's recap.
 *
 * Composes the persisted exchange-graph projection with the live podium
 * (RunResultsQuery, reused - handles ranking/released/invalidated) and the
 * event VOD. Returns null - i.e. the controller 404s - when the session is not
 * finished, no projection has been built yet, or the viewer is not allowed to see it.
 *
 * Access (story 32.5) is delegated to {@see SessionRecapAudience}, shared with the feed timeline so the
 * one rule cannot drift: public event -> anyone; personal run -> owner/participant, or anyone once
 * published. Weekly runs never reach this (no finished session).
 */
final readonly class SessionRecapQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionRecapRepositoryInterface $recaps,
        private RunResultsQuery $runResults,
        private SessionRecapAudience $audience,
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
    public function execute(string $sessionId, ?string $viewerId = null): ?array
    {
        $session = $this->sessions->findById($sessionId);
        if (null === $session || Session::STATUS_FINISHED !== $session->getStatus()) {
            return null;
        }

        if (!$this->audience->canView($session, $viewerId)) {
            return null;
        }

        $recap = $this->recaps->findBySessionId($sessionId);
        if (null === $recap) {
            return null;
        }

        // Podium already resolves the display name for both event and personal-run sessions.
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
            'vodUrl' => $this->audience->vodUrl($session),
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
