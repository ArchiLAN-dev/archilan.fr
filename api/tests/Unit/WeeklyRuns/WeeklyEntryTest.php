<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Domain\WeeklyEntry;
use PHPUnit\Framework\TestCase;

final class WeeklyEntryTest extends TestCase
{
    public function testLaunchSetsExternalSessionId(): void
    {
        $entry = $this->makeEntry();
        $launchedAt = new \DateTimeImmutable('2026-05-20T10:00:00+00:00');

        $entry->launch('session-uuid-1234', $launchedAt, $this->connectionInfo());

        self::assertSame('session-uuid-1234', $entry->getExternalSessionId());
    }

    public function testLaunchSetsLaunchedAt(): void
    {
        $entry = $this->makeEntry();
        $launchedAt = new \DateTimeImmutable('2026-05-20T10:00:00+00:00');

        $entry->launch('session-uuid-1234', $launchedAt, $this->connectionInfo());

        self::assertEquals($launchedAt, $entry->getLaunchedAt());
    }

    public function testLaunchUpdatesUpdatedAt(): void
    {
        $entry = $this->makeEntry();
        $launchedAt = new \DateTimeImmutable('2026-05-20T10:00:00+00:00');

        $entry->launch('session-uuid-1234', $launchedAt, $this->connectionInfo());

        self::assertEquals($launchedAt, $entry->getUpdatedAt());
    }

    public function testRecordGoalSetsGoalReachedAt(): void
    {
        $entry = $this->makeEntry();
        $goalReachedAt = new \DateTimeImmutable('2026-05-21T15:30:00+00:00');

        $entry->recordGoal($goalReachedAt, 141600, 42, 87);

        self::assertEquals($goalReachedAt, $entry->getGoalReachedAt());
    }

    public function testRecordGoalSetsCompletionTimeSeconds(): void
    {
        $entry = $this->makeEntry();
        $goalReachedAt = new \DateTimeImmutable('2026-05-21T15:30:00+00:00');

        $entry->recordGoal($goalReachedAt, 141600, 42, 87);

        self::assertSame(141600, $entry->getCompletionTimeSeconds());
    }

    public function testRecordGoalSetsChecksAndItemsTotals(): void
    {
        $entry = $this->makeEntry();
        $goalReachedAt = new \DateTimeImmutable('2026-05-21T15:30:00+00:00');

        $entry->recordGoal($goalReachedAt, 141600, 42, 87);

        self::assertSame(42, $entry->getChecksTotal());
        self::assertSame(87, $entry->getItemsTotal());
    }

    public function testRecordGoalUpdatesUpdatedAt(): void
    {
        $entry = $this->makeEntry();
        $goalReachedAt = new \DateTimeImmutable('2026-05-21T15:30:00+00:00');

        $entry->recordGoal($goalReachedAt, 141600, 42, 87);

        self::assertEquals($goalReachedAt, $entry->getUpdatedAt());
    }

    public function testLaunchThrowsWhenAlreadyLaunched(): void
    {
        $entry = $this->makeEntry();
        $first = new \DateTimeImmutable('2026-05-20T10:00:00+00:00');
        $entry->launch('session-uuid-1234', $first, $this->connectionInfo());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('session_already_started');

        $entry->launch('session-uuid-5678', new \DateTimeImmutable('2026-05-20T11:00:00+00:00'), $this->connectionInfo());
    }

    public function testRecordGoalIsNoOpWhenAlreadyRecorded(): void
    {
        $entry = $this->makeEntry();
        $first = new \DateTimeImmutable('2026-05-21T15:30:00+00:00');
        $entry->recordGoal($first, 141600, 42, 87);

        $second = new \DateTimeImmutable('2026-05-21T16:00:00+00:00');
        $entry->recordGoal($second, 999999, 99, 99);

        self::assertEquals($first, $entry->getGoalReachedAt());
        self::assertSame(141600, $entry->getCompletionTimeSeconds());
    }

    public function testNewEntryHasNullableFieldsAsNull(): void
    {
        $entry = $this->makeEntry();

        self::assertNull($entry->getExternalSessionId());
        self::assertNull($entry->getLaunchedAt());
        self::assertNull($entry->getGoalReachedAt());
        self::assertNull($entry->getCompletionTimeSeconds());
        self::assertNull($entry->getChecksTotal());
        self::assertNull($entry->getItemsTotal());
    }

    public function testGetConnectionInfoReturnsNullBeforeLaunch(): void
    {
        self::assertNull($this->makeEntry()->getConnectionInfo());
    }

    public function testGetConnectionInfoReturnsInfoAfterLaunch(): void
    {
        $entry = $this->makeEntry();
        $entry->launch('session-uuid-1234', new \DateTimeImmutable('2026-05-20T10:00:00+00:00'), $this->connectionInfo());

        self::assertSame(['host' => 'runner.test', 'port' => 38281, 'password' => null], $entry->getConnectionInfo());
    }

    private function makeEntry(): WeeklyEntry
    {
        $now = new \DateTimeImmutable('2026-05-19T00:00:00+00:00');

        return new WeeklyEntry(
            id: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            weeklyRunId: 'ffffffff-1111-2222-3333-444444444444',
            userId: '55555555-6666-7777-8888-999999999999',
            attemptNumber: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    /** @return array{host: string, port: int, password: string|null} */
    private function connectionInfo(): array
    {
        return ['host' => 'runner.test', 'port' => 38281, 'password' => null];
    }
}
