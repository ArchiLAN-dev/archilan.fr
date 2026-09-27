<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Repository\DiscordBanBaselineRepositoryInterface;

/**
 * Whether the Discord bans present at activation were told to the staff (story 39.9 tests).
 */
final class InMemoryDiscordBanBaselineRepository implements DiscordBanBaselineRepositoryInterface
{
    public function __construct(public bool $taken = true)
    {
    }

    public function isTaken(): bool
    {
        return $this->taken;
    }

    public function markTaken(\DateTimeImmutable $now): void
    {
        $this->taken = true;
    }
}
