<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

/**
 * When each player of a run's session last made a check (story 43.12), and whether all their slots are done -
 * released or goal reached. A player who never checked anything counts from the session's first start.
 */
final readonly class RunPlayersActivity
{
    public const string IDLE_AFTER = '-48 hours';

    /**
     * @param array<string, array{lastCheckAt: \DateTimeImmutable|null, finished: bool}> $players keyed by user id
     */
    public function __construct(
        public ?\DateTimeImmutable $startedAt,
        public array $players,
    ) {
    }

    public function plays(string $userId): bool
    {
        return isset($this->players[$userId]);
    }

    /** No check for IDLE_AFTER, slots still to play: someone the others may nudge. */
    public function isIdle(string $userId, \DateTimeImmutable $now): bool
    {
        $player = $this->players[$userId] ?? null;
        if (null === $player || $player['finished']) {
            return false;
        }
        $since = $player['lastCheckAt'] ?? $this->startedAt;

        return null !== $since && $since <= $now->modify(self::IDLE_AFTER);
    }
}
