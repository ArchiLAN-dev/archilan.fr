<?php

declare(strict_types=1);

namespace App\Community\Application\Message;

/**
 * Something a member did that the friends who starred them may want to hear of (story 43.11b), dispatched after the
 * write commits. The handler resolves who did it, who is told and what they may know.
 *
 *  - `registered`: `contextId` is the event, `actorId` the member who registered;
 *  - `session_started`: `contextId` is the session, its players are the actors;
 *  - `goal_reached`: `contextId` is the session, the players of `slotName` are the actors.
 */
final readonly class FriendActivityJob
{
    public const string REGISTERED = 'registered';
    public const string SESSION_STARTED = 'session_started';
    public const string GOAL_REACHED = 'goal_reached';

    public function __construct(
        public string $kind,
        public string $contextId,
        public ?string $actorId = null,
        public ?string $slotName = null,
    ) {
    }

    public static function registered(string $eventId, string $userId): self
    {
        return new self(self::REGISTERED, $eventId, $userId);
    }

    public static function sessionStarted(string $sessionId): self
    {
        return new self(self::SESSION_STARTED, $sessionId);
    }

    public static function goalReached(string $sessionId, string $slotName): self
    {
        return new self(self::GOAL_REACHED, $sessionId, null, $slotName);
    }
}
