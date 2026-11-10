<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\PasseurOpening;

interface PasseurOpeningRepositoryInterface
{
    /**
     * Story 52.4: writes the pending opening unless its chest is already being opened (or was) or its request was
     * already made. Returns the opening to settle, or null when nothing was written.
     */
    public function claim(PasseurOpening $opening): ?PasseurOpening;

    public function findByRequestKey(string $requestKey): ?PasseurOpening;

    /** The openings of an absent player's world in [from, to), failed ones left out (the daily limit). */
    public function countForWorld(string $sessionId, int $findingSlot, \DateTimeImmutable $from, \DateTimeImmutable $to): int;

    /** Story 52.5: the absent player closed their notice - every opening it listed leaves it. */
    public function markSeenByAbsent(string $sessionId, string $absentUserId, \DateTimeImmutable $now): void;

    public function flush(): void;
}
