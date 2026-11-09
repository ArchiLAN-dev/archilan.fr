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
}
