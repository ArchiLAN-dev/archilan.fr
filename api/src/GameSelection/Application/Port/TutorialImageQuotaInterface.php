<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Port;

/** How many tutorial images an account may still store (story 50.3). */
interface TutorialImageQuotaInterface
{
    /** Takes one upload from the account's allowance; false when none is left. */
    public function consume(string $userId): bool;
}
