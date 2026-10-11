<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Query\RunPlayersActivity;
use PHPUnit\Framework\TestCase;

/**
 * Story 43.12: a player is idle after 48 hours without a check, counted from the session's start when they never
 * checked anything, and never once all their slots are done.
 */
final class RunPlayersActivityTest extends TestCase
{
    public function testIdleFromFortyEightHoursWithoutACheck(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T12:00:00+00:00');
        $activity = new RunPlayersActivity(new \DateTimeImmutable('2026-10-01T12:00:00+00:00'), [
            'exact' => ['lastCheckAt' => new \DateTimeImmutable('2026-10-08T12:00:00+00:00'), 'finished' => false],
            'recent' => ['lastCheckAt' => new \DateTimeImmutable('2026-10-08T12:00:01+00:00'), 'finished' => false],
            'never' => ['lastCheckAt' => null, 'finished' => false],
            'done' => ['lastCheckAt' => new \DateTimeImmutable('2026-10-02T12:00:00+00:00'), 'finished' => true],
        ]);

        self::assertTrue($activity->isIdle('exact', $now));
        self::assertFalse($activity->isIdle('recent', $now));
        self::assertTrue($activity->isIdle('never', $now));
        self::assertFalse($activity->isIdle('done', $now));
        self::assertFalse($activity->isIdle('stranger', $now));
    }

    public function testNeverCheckedOnAFreshSessionIsNotIdle(): void
    {
        $now = new \DateTimeImmutable('2026-10-10T12:00:00+00:00');
        $fresh = new RunPlayersActivity(new \DateTimeImmutable('2026-10-09T12:00:00+00:00'), ['never' => ['lastCheckAt' => null, 'finished' => false]]);
        $unstarted = new RunPlayersActivity(null, ['never' => ['lastCheckAt' => null, 'finished' => false]]);

        self::assertFalse($fresh->isIdle('never', $now));
        self::assertFalse($unstarted->isIdle('never', $now));
    }
}
