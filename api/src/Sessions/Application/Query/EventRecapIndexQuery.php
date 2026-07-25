<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRecapRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

/**
 * Public index of a public event's finished sessions that have a recap projection - the
 * "toutes les parties de cet event" discovery surface (story 32.3).
 *
 * Mirrors SessionRecapQuery's privacy gating: unknown or non-public event returns null (the
 * controller 404s); finished sessions without a persisted projection stay invisible, so the
 * index never links to a /parties page that would itself 404.
 */
final readonly class EventRecapIndexQuery
{
    public function __construct(
        private EventRepositoryInterface $events,
        private SessionRepositoryInterface $sessions,
        private SessionRecapRepositoryInterface $recaps,
        private RunResultsQuery $runResults,
    ) {
    }

    /**
     * @return list<array{
     *     sessionId: string,
     *     startedAt: string|null,
     *     finishedAt: string|null,
     *     durationSeconds: int|null,
     *     playerCount: int,
     *     winner: array{playerName: string, game: string}|null
     * }>|null
     */
    public function execute(string $eventId): ?array
    {
        $event = $this->events->findById($eventId);
        if (null === $event || !$event->isPublic()) {
            return null;
        }

        /** @var list<array{0: int, 1: array{sessionId: string, startedAt: string|null, finishedAt: string|null, durationSeconds: int|null, playerCount: int, winner: array{playerName: string, game: string}|null}}> $sortable */
        $sortable = [];
        foreach ($this->sessions->findByEventId($eventId) as $session) {
            if (Session::STATUS_FINISHED !== $session->getStatus()) {
                continue;
            }
            if (null === $this->recaps->findBySessionId($session->getId())) {
                continue;
            }

            $results = $this->runResults->execute($session->getId());
            if (null === $results) {
                continue;
            }

            $sortable[] = [
                $session->getFinishedAt()?->getTimestamp() ?? 0,
                [
                    'sessionId' => $session->getId(),
                    'startedAt' => $results['startedAt'],
                    'finishedAt' => $results['finishedAt'],
                    'durationSeconds' => $results['durationSeconds'],
                    'playerCount' => \count($results['slots']),
                    'winner' => $this->winner($results['slots']),
                ],
            ];
        }

        usort($sortable, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(static fn (array $tuple) => $tuple[1], $sortable);
    }

    /**
     * The ranked slots come pre-sorted by RunResultsQuery (fastest goal first) - the winner is
     * the first one that actually reached its goal, or null when nobody did.
     *
     * @param list<array<string, mixed>> $slots
     *
     * @return array{playerName: string, game: string}|null
     */
    private function winner(array $slots): ?array
    {
        foreach ($slots as $slot) {
            if (!isset($slot['goalReachedAt']) || !\is_string($slot['goalReachedAt'])) {
                continue;
            }
            $playerName = $slot['playerName'] ?? null;
            $game = $slot['game'] ?? null;
            if (!\is_string($playerName) || !\is_string($game)) {
                continue;
            }

            return ['playerName' => $playerName, 'game' => $game];
        }

        return null;
    }
}
