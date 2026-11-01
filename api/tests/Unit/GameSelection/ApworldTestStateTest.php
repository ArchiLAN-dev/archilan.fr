<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Support\ApworldTestState;
use PHPUnit\Framework\TestCase;

/** Story 38.33: a test that runs, one that waits, one that is over, and none at all. */
final class ApworldTestStateTest extends TestCase
{
    public function testAPendingTestThatStartedIsRunning(): void
    {
        self::assertSame(ApworldTestState::Running, ApworldTestState::of(['status' => 'pending', 'startedAt' => '2026-11-01T12:00:00+00:00']));
    }

    public function testAPendingTestNotStartedWaitsInTheQueue(): void
    {
        self::assertSame(ApworldTestState::Queued, ApworldTestState::of(['status' => 'pending', 'startedAt' => null]));
        self::assertSame(ApworldTestState::Queued, ApworldTestState::of(['status' => 'pending']));
    }

    public function testASettledVerdictIsFinished(): void
    {
        foreach (['passed', 'failed', 'skipped'] as $status) {
            self::assertSame(ApworldTestState::Finished, ApworldTestState::of(['status' => $status]));
        }
    }

    public function testNoVerdictMeansNoTestIsComing(): void
    {
        self::assertSame(ApworldTestState::Idle, ApworldTestState::of(null));
        self::assertSame(ApworldTestState::Idle, ApworldTestState::of(['status' => '']));
        self::assertFalse(ApworldTestState::Idle->isUnderway());
        self::assertTrue(ApworldTestState::Queued->isUnderway());
    }
}
