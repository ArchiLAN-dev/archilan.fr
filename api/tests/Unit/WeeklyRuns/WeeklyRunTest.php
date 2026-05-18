<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Domain\WeeklyRun;
use PHPUnit\Framework\TestCase;

final class WeeklyRunTest extends TestCase
{
    public function testFinish_setsStatusToFinished(): void
    {
        $run = $this->makeRun();
        $finishedAt = new \DateTimeImmutable('2026-05-24T23:59:00+00:00');

        $run->finish($finishedAt);

        self::assertSame(WeeklyRun::STATUS_FINISHED, $run->getStatus());
    }

    public function testFinish_setsFinishedAt(): void
    {
        $run = $this->makeRun();
        $finishedAt = new \DateTimeImmutable('2026-05-24T23:59:00+00:00');

        $run->finish($finishedAt);

        self::assertEquals($finishedAt, $run->getFinishedAt());
    }

    public function testNewRun_hasNullFinishedAt(): void
    {
        $run = $this->makeRun();

        self::assertNull($run->getFinishedAt());
    }

    public function testNewRun_hasActiveStatus(): void
    {
        $run = $this->makeRun();

        self::assertSame(WeeklyRun::STATUS_ACTIVE, $run->getStatus());
    }

    public function testFinish_isNoOpWhenAlreadyFinished(): void
    {
        $run = $this->makeRun();
        $first = new \DateTimeImmutable('2026-05-24T23:59:00+00:00');
        $run->finish($first);

        $second = new \DateTimeImmutable('2026-05-25T01:00:00+00:00');
        $run->finish($second);

        self::assertEquals($first, $run->getFinishedAt());
    }

    private function makeRun(): WeeklyRun
    {
        $now = new \DateTimeImmutable('2026-05-19T00:00:00+00:00');

        return new WeeklyRun(
            id: 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            templateId: 'ffffffff-1111-2222-3333-444444444444',
            weekYear: 2026,
            weekNumber: 21,
            seed: 'archilan-weekly-2026-21',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );
    }
}
