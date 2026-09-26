<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Port\ExclusivePassLockInterface;

/**
 * Test double: `held` pretends another process runs the pass.
 */
final class InMemoryExclusivePassLock implements ExclusivePassLockInterface
{
    /** @var list<string> */
    public array $released = [];

    public function __construct(private readonly bool $held = false)
    {
    }

    public function tryAcquire(string $pass): bool
    {
        return !$this->held;
    }

    public function release(string $pass): void
    {
        $this->released[] = $pass;
    }
}
