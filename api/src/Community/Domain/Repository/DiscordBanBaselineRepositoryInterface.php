<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

interface DiscordBanBaselineRepositoryInterface
{
    public function isTaken(): bool;

    /** Written at once. */
    public function markTaken(\DateTimeImmutable $now): void;
}
