<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

interface RecapExchangesQueryInterface
{
    /**
     * Who plays each slot of the session, owners and co-players (story 43.10).
     *
     * @return list<array{slotName: string, userIds: list<string>}> slot names may be numeric: not array keys
     */
    public function playersBySlot(string $sessionId): array;

    /**
     * The items the given slots exchanged with each other slot of the session, from the persisted feed, per other
     * slot: sent to it, received from it, and how many of each were progression items.
     *
     * @param list<string> $slotNames
     *
     * @return list<array{slotName: string, sent: int, received: int, sentProgression: int, receivedProgression: int}> per other slot
     */
    public function exchanges(string $sessionId, array $slotNames): array;

    /**
     * For each BK one of the given slots got out of, the last progression item another slot sent it in the window
     * before the release: the item that got the player out.
     *
     * @param list<string> $slotNames
     *
     * @return list<array{slotName: string, itemName: string|null, senderName: string, at: string}>
     */
    public function unblockingItems(string $sessionId, array $slotNames, int $windowSeconds): array;
}
