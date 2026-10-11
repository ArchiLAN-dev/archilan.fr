<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

/**
 * Story 43.28: a moment of a personal run every player hears of - its server is up for the first time, or its recap
 * is ready. Dispatched by the session side for any session; a session that is not a personal run's is ignored.
 */
final readonly class NotifyRunPlayersJob
{
    public const string STARTED = 'started';

    public const string RECAP_READY = 'recap_ready';

    private function __construct(
        public string $moment,
        public string $sessionId,
    ) {
    }

    public static function started(string $sessionId): self
    {
        return new self(self::STARTED, $sessionId);
    }

    public static function recapReady(string $sessionId): self
    {
        return new self(self::RECAP_READY, $sessionId);
    }
}
