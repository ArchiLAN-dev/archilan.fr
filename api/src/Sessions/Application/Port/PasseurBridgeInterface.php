<?php

declare(strict_types=1);

namespace App\Sessions\Application\Port;

use App\Sessions\Domain\ValueObject\PasseurItem;

/**
 * Story 52.3: what the session's bridge knows for the Passeur.
 */
interface PasseurBridgeInterface
{
    /** At most this many candidates are measured by the tracker in one call: each is a full computation. */
    public const int MAX_MEASURED = 10;

    /** Story 52.4: what became of a chest the Passeur was asked to open. */
    public const string OPENED = 'opened';
    public const string ALREADY_OPENED = 'already_opened';
    public const string NOT_OPENED = 'not_opened';

    /**
     * The slot's items not received yet, in other worlds' unopened locations; null when the bridge cannot say.
     *
     * @return list<PasseurItem>|null
     */
    public function items(string $sessionId, int $slotIndex): ?array;

    /**
     * How many of the slot's locations each candidate would make reachable, by PasseurItem::key(); null when the
     * game is not tracked or the bridge cannot say.
     *
     * @param list<PasseurItem> $candidates at most MAX_MEASURED
     *
     * @return array<string, int>|null
     */
    public function gains(string $sessionId, int $slotIndex, array $candidates): ?array;

    /**
     * Story 52.4: opens a location of a world, as if its player had checked it (`/send_location`). OPENED when the
     * server sent its item; ALREADY_OPENED when it was checked before; NOT_OPENED otherwise. An answer the bridge
     * could not confirm (a timeout) is settled by reading the location's state again.
     *
     * @return self::OPENED|self::ALREADY_OPENED|self::NOT_OPENED
     */
    public function open(string $sessionId, int $findingSlot, int $locationId): string;
}
