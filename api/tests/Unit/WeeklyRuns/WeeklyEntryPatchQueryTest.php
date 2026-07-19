<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Application\Query\WeeklyEntryPatchQuery;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Repository\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\Repository\WeeklyRunRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Patch-source resolution for a weekly entry (issue #262). A launched orchestrator entry must resolve
 * to the run's DURABLE MinIO archive, never the live bridge port: the host port is freed on stop and
 * reused by other sessions, so reading it would leak another running party's patch files.
 */
final class WeeklyEntryPatchQueryTest extends TestCase
{
    private const string RUN_ID = 'run-1';
    private const string ENTRY_ID = 'entry-1';
    private const string USER_ID = 'user-1';
    private const string WORKSPACE = '/workspace';

    public function testOrchestratorEntryResolvesToDurableRunArchive(): void
    {
        $query = $this->buildQuery(
            entry: $this->launchedEntry(bridgePort: 5000),
            run: $this->weeklyRun(outputKey: 'sessions-bucket-key/archive.zip'),
        );

        $context = $query->forEntry(self::RUN_ID, self::ENTRY_ID, self::USER_ID);

        self::assertSame(['type' => 'durable', 'outputKey' => 'sessions-bucket-key/archive.zip'], $context);
    }

    public function testReturnsNullWhenRunHasNoDurableArchive(): void
    {
        $query = $this->buildQuery(
            entry: $this->launchedEntry(bridgePort: 5000),
            run: $this->weeklyRun(outputKey: null),
        );

        self::assertNull($query->forEntry(self::RUN_ID, self::ENTRY_ID, self::USER_ID));
    }

    public function testForeignUserIsDenied(): void
    {
        $query = $this->buildQuery(
            entry: $this->launchedEntry(bridgePort: 5000),
            run: $this->weeklyRun(outputKey: 'k'),
        );

        self::assertNull($query->forEntry(self::RUN_ID, self::ENTRY_ID, 'someone-else'));
    }

    public function testMismatchedRunIsDenied(): void
    {
        $query = $this->buildQuery(
            entry: $this->launchedEntry(bridgePort: 5000),
            run: $this->weeklyRun(outputKey: 'k'),
        );

        self::assertNull($query->forEntry('other-run', self::ENTRY_ID, self::USER_ID));
    }

    public function testLegacyDockerEntryResolvesToLocalDir(): void
    {
        // No bridge port => legacy Docker session => local filesystem, keyed by the frozen session id.
        $query = $this->buildQuery(
            entry: $this->launchedEntry(bridgePort: null, externalSessionId: 'sess-legacy'),
            run: $this->weeklyRun(outputKey: 'k'),
        );

        $context = $query->forEntry(self::RUN_ID, self::ENTRY_ID, self::USER_ID);

        self::assertSame(
            ['type' => 'local', 'outputDir' => self::WORKSPACE.'/sess-legacy/output', 'slotName' => null],
            $context,
        );
    }

    private function buildQuery(WeeklyEntry $entry, WeeklyRun $run): WeeklyEntryPatchQuery
    {
        $entries = self::createStub(WeeklyEntryRepositoryInterface::class);
        $entries->method('findById')->willReturn($entry);

        $runs = self::createStub(WeeklyRunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        return new WeeklyEntryPatchQuery($entries, $runs, self::WORKSPACE);
    }

    private function launchedEntry(?int $bridgePort, string $externalSessionId = 'sess-1'): WeeklyEntry
    {
        $now = new \DateTimeImmutable();
        $entry = new WeeklyEntry(self::ENTRY_ID, self::RUN_ID, self::USER_ID, 1, $now, $now);
        $entry->launch($externalSessionId, $now, ['host' => 'h', 'port' => 1, 'password' => null], $bridgePort);

        return $entry;
    }

    private function weeklyRun(?string $outputKey): WeeklyRun
    {
        $now = new \DateTimeImmutable();

        return new WeeklyRun(
            self::RUN_ID,
            'template-1',
            2026,
            1,
            'seed',
            WeeklyRun::STATUS_ACTIVE,
            $now,
            $now,
            null,
            $outputKey,
        );
    }
}
