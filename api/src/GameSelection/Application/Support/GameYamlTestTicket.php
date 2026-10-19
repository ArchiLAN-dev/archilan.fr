<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

/**
 * Story 9.57: what a player reads the result of their YAML test with.
 *
 * The orchestrator's job id alone would let anyone who learns it read someone else's result. The ticket
 * binds it to the player and the game with an HMAC, so the API keeps no record of who started what: the
 * orchestrator forgets its results after thirty minutes anyway.
 */
final readonly class GameYamlTestTicket
{
    public function __construct(private string $appSecret)
    {
    }

    public function issue(string $userId, string $gameId, string $jobId): string
    {
        return $jobId.'.'.$this->signature($userId, $gameId, $jobId);
    }

    /** The job id the ticket was issued for, or null when it was not issued to this player for this game. */
    public function jobId(string $userId, string $gameId, string $ticket): ?string
    {
        $separator = strrpos($ticket, '.');
        if (false === $separator) {
            return null;
        }
        $jobId = substr($ticket, 0, $separator);
        if ('' === $jobId || !hash_equals($this->signature($userId, $gameId, $jobId), substr($ticket, $separator + 1))) {
            return null;
        }

        return $jobId;
    }

    private function signature(string $userId, string $gameId, string $jobId): string
    {
        return hash_hmac('sha256', 'game-yaml-test|'.$userId.'|'.$gameId.'|'.$jobId, $this->appSecret);
    }
}
