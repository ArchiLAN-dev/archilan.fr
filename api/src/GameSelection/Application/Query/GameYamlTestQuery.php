<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

use App\GameSelection\Application\Support\GameYamlTestTicket;

/**
 * Story 9.57: where a player's YAML test stands. A ticket issued to someone else, or for another game,
 * reads as unknown, like an expired test.
 */
final readonly class GameYamlTestQuery
{
    public function __construct(
        private GameYamlTestTicket $tickets,
        private YamlTestResultQuery $results,
    ) {
    }

    /**
     * @return array{status: string, error: string|null}|null
     */
    public function result(string $userId, string $gameId, string $ticket): ?array
    {
        $jobId = $this->tickets->jobId($userId, $gameId, $ticket);

        return null === $jobId ? null : $this->results->result($jobId);
    }
}
