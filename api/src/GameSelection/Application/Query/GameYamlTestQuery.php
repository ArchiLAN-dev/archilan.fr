<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\GameSelection\Application\Support\GameYamlTestTicket;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Service\SlotDurationEstimate;

/**
 * Story 9.57: where a player's YAML test stands. A ticket issued to someone else, or for another game,
 * reads as unknown, like an expired test.
 *
 * Story 56.5: a passed test also says how long the world should take this player (story 56.2), and, when the test is
 * read for an event or a private run the player is in, the target it is measured against. Past the target the result
 * warns, nothing is blocked.
 */
final readonly class GameYamlTestQuery
{
    public function __construct(
        private GameYamlTestTicket $tickets,
        private YamlTestResultQuery $results,
        private SlotDurationEstimate $durations,
        private EventRepositoryInterface $events,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
    ) {
    }

    /**
     * @return array{status: string, error: string|null, measures: list<array{name: string, game: string, locations: int, ownProgression: int}>, estimate: array{locations: int|null, hours: float|null, source: string, targetHours: float|null, overTarget: bool}}|null
     */
    public function result(string $userId, string $gameId, string $ticket, ?string $eventId = null, ?string $runId = null): ?array
    {
        $jobId = $this->tickets->jobId($userId, $gameId, $ticket);
        $result = null === $jobId ? null : $this->results->result($jobId);
        if (null === $result) {
            return null;
        }

        // A solo test generates one world: the player's.
        $locations = [] === $result['measures'] ? null : $result['measures'][0]['locations'];
        $duration = $this->durations->forSlot($gameId, [$userId], $locations);
        $target = $this->targetHours($userId, $eventId, $runId);
        $hours = null === $duration->hours ? null : round($duration->hours, 1);

        return [...$result, 'estimate' => [
            'locations' => $locations,
            'hours' => $hours,
            'source' => $duration->source->value,
            'targetHours' => null === $target ? null : round($target, 1),
            'overTarget' => null !== $hours && null !== $target && $hours > $target,
        ]];
    }

    private function targetHours(string $userId, ?string $eventId, ?string $runId): ?float
    {
        if (null !== $eventId) {
            $event = $this->events->findById($eventId);

            return $event instanceof Event ? $event->targetHours() : null;
        }
        if (null !== $runId) {
            $run = $this->runs->findById($runId);
            if (!$run instanceof Run) {
                return null;
            }
            // A run's target is read by the people in it only.
            $isIn = $run->isOwnedBy($userId) || $this->participants->findByRunAndUser($runId, $userId) instanceof RunParticipant;
            $hours = $run->getTargetHours();

            return $isIn && null !== $hours ? (float) $hours : null;
        }

        return null;
    }
}
