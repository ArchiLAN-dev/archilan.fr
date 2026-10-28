<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\SlotPlaySpan;

interface SlotPlaySpanRepositoryInterface
{
    public function findLatest(string $sessionId, string $slotName): ?SlotPlaySpan;

    /**
     * The play time of each slot of a session that has some, in seconds (story 32.26).
     *
     * @return array<string, int> slot name => seconds
     */
    public function playedSecondsBySlotName(string $sessionId): array;

    public function add(SlotPlaySpan $span): void;

    public function flush(): void;
}
