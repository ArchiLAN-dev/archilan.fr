<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * What the alerts of story 43.11b read: who plays a session or one of its slots, an event worth announcing, and the
 * alerts a member already received (for the anti-noise caps).
 */
interface FriendActivitySourceQueryInterface
{
    /**
     * The members playing a session (owners and co-players of its slots).
     *
     * @return list<string>
     */
    public function sessionPlayers(string $sessionId): array;

    /**
     * The members playing one slot of a session.
     *
     * @return list<string>
     */
    public function slotPlayers(string $sessionId, string $slotName): array;

    /** The title of a public event still to come, null for any other event. */
    public function upcomingPublicEventTitle(string $eventId, \DateTimeImmutable $now): ?string;

    /** Story 43.19: whether the session belongs to a personal run its owner opened to friends (43.14) or to all (43.17). */
    public function isRunOpenToFriends(string $sessionId): bool;

    /**
     * The friend activity alerts a member received since a moment: who each one named.
     *
     * @return list<string|null> the actor of each alert
     */
    public function alertActorsSince(string $recipientId, \DateTimeImmutable $since): array;

    /**
     * The friend activity alerts a member received since a moment, naming a given actor.
     */
    public function hasAlertFromSince(string $recipientId, string $actorId, \DateTimeImmutable $since): bool;
}
