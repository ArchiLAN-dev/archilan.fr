<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Infrastructure\Dbal\PostgresAdvisoryPassLock;
use Doctrine\DBAL\Connection;

/**
 * Story 38.1 review: two reconciliation passes must not run at once, across processes.
 */
final class PostgresAdvisoryPassLockTest extends FunctionalTestCase
{
    public function testASecondProcessCannotTakeAPassHeldByAnother(): void
    {
        $connection = $this->entityManager->getConnection();
        // A second session, as another worker process would have.
        $otherProcess = new Connection($connection->getParams(), $connection->getDriver(), $connection->getConfiguration());
        $mine = new PostgresAdvisoryPassLock($connection);
        $theirs = new PostgresAdvisoryPassLock($otherProcess);

        self::assertTrue($mine->tryAcquire('apworld_incidents_reconcile'));
        self::assertFalse($theirs->tryAcquire('apworld_incidents_reconcile'));
        self::assertTrue($theirs->tryAcquire('another_pass'), 'a lock is per pass');

        $mine->release('apworld_incidents_reconcile');
        self::assertTrue($theirs->tryAcquire('apworld_incidents_reconcile'));

        $theirs->release('apworld_incidents_reconcile');
        $theirs->release('another_pass');
        $otherProcess->close();
    }
}
