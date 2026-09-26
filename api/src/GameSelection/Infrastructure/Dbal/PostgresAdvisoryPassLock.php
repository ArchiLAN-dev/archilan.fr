<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Dbal;

use App\GameSelection\Application\Port\ExclusivePassLockInterface;
use Doctrine\DBAL\Connection;

/**
 * A pass lock held by a PostgreSQL session advisory lock (story 38.1 review): shared by every worker
 * and console process on the same database, without any extra dependency. Non-blocking - a pass
 * that is already running is skipped, not waited for - and released with the session if the
 * process dies, so a crash never leaves the pass locked.
 */
final readonly class PostgresAdvisoryPassLock implements ExclusivePassLockInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function tryAcquire(string $pass): bool
    {
        return true === $this->connection->fetchOne('SELECT pg_try_advisory_lock(hashtext(:pass))', ['pass' => $pass]);
    }

    public function release(string $pass): void
    {
        $this->connection->executeQuery('SELECT pg_advisory_unlock(hashtext(:pass))', ['pass' => $pass]);
    }
}
