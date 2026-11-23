<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

use App\GameSelection\Application\Support\GameYamlTestTicket;
use App\GameSelection\Application\Support\YamlTestTarget;
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
        private YamlTestTarget $targets,
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
        $target = $this->targets->hours($userId, $eventId, $runId);
        $hours = null === $duration->hours ? null : round($duration->hours, 1);

        return [...$result, 'estimate' => [
            'locations' => $locations,
            'hours' => $hours,
            'source' => $duration->source->value,
            'targetHours' => null === $target ? null : round($target, 1),
            'overTarget' => null !== $hours && null !== $target && $hours > $target,
        ]];
    }
}
