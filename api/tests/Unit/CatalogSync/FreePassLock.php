<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\GameSelection\Application\Port\ExclusivePassLockInterface;

/**
 * A pass lock for unit tests: free unless told another process holds it.
 */
final class FreePassLock implements ExclusivePassLockInterface
{
    public int $releases = 0;

    public function __construct(private readonly bool $heldElsewhere = false)
    {
    }

    public function tryAcquire(string $pass): bool
    {
        return !$this->heldElsewhere;
    }

    public function release(string $pass): void
    {
        ++$this->releases;
    }
}
