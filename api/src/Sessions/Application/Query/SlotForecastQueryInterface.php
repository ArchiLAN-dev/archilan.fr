<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

/**
 * Story 56.8: what the live forecast of an event and the comparison of expected and real durations read, from the
 * session slots and the feed the bridge already sends. Every feed read is bounded by one session.
 */
interface SlotForecastQueryInterface
{
    /**
     * The players of each slot of a session, co-players included.
     *
     * @return array<string, list<string>> slot row id => user ids
     */
    public function slotPlayers(string $sessionId): array;

    /** The event's running session, the most recently started one; null when none runs. */
    public function runningSession(string $eventId): ?string;

    /**
     * The slots of a session with their progress. `recentChecks` counts the checks of the slot's last hour of play
     * (the hour before its last check, from the start of the session at most), `recentSeconds` the length of that
     * window; `hostedSent` the progression items of the others already found in its world.
     *
     * @return list<array{slotId: string, slotName: string, gameId: string, gameName: string, checksDone: int, locations: int|null, hostedProgression: int|null, goalReached: bool, released: bool, recentChecks: int, recentSeconds: int, hostedSent: int}>
     */
    public function liveSlots(string $sessionId, int $windowSeconds): array;

    /**
     * The slots of the event's sessions that reached their goal with an expected duration, and how long they took.
     *
     * @return list<array{slotName: string, gameName: string, estimatedHours: float, actualHours: float}>
     */
    public function eventCalibration(string $eventId): array;

    /**
     * The same comparison over every slot of a game: how many, and the mean of real over expected.
     *
     * @return array{slots: int, meanRatio: float}|null null when no slot compares
     */
    public function gameCalibration(string $gameId): ?array;
}
