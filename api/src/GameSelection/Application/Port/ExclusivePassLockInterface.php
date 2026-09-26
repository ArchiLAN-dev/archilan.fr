<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Port;

/**
 * Keeps a scheduled pass from running twice at once (story 38.1 review): the five-minute message and
 * the console command, or a pass that outlives its interval. The second one skips; the next pass
 * catches up, every pass being idempotent.
 */
interface ExclusivePassLockInterface
{
    /** False when another process holds the pass. */
    public function tryAcquire(string $pass): bool;

    public function release(string $pass): void;
}
