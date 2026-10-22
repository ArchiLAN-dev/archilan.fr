<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Port;

/**
 * Story 9.57: how many YAML test generations a player may start. Every test is a real generation on the
 * shared runner.
 */
interface YamlTestQuotaInterface
{
    /** Takes one test from the player's allowance; false when it is spent. Atomic across requests. */
    public function consume(string $userId): bool;
}
