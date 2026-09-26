<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\ApworldHealth;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.9: what an apworld's verdicts have shown over time, to confirm a failure before alerting.
 */
final class ApworldHealthTest extends TestCase
{
    public function testFailureAfterASuccessCountsOneConsecutiveFailure(): void
    {
        $health = ApworldHealth::start('h-1', 'game-1', 'hash-1');
        $health->recordVerdict('passed', '2026-09-20T05:00:00Z', 'archipelago:0.16.0', 'sha256:old');

        self::assertTrue($health->recordVerdict('failed', '2026-09-27T05:00:00Z', 'archipelago:0.16.1', 'sha256:new'));

        self::assertTrue($health->hasPassedBefore());
        self::assertSame(1, $health->getConsecutiveFailures());
        self::assertSame('failed', $health->getLastStatus());
    }

    public function testSuccessResetsTheCounterAndRecordsTheImage(): void
    {
        $health = ApworldHealth::start('h-1', 'game-1', 'hash-1');
        $health->recordVerdict('failed', '2026-09-20T05:00:00Z', 'archipelago:0.16.0', 'sha256:old');
        $health->recordVerdict('failed', '2026-09-21T05:00:00Z', 'archipelago:0.16.0', 'sha256:old');

        $health->recordVerdict('passed', '2026-09-22T05:00:00Z', 'archipelago:0.16.1', 'sha256:new');

        self::assertSame(0, $health->getConsecutiveFailures());
        self::assertSame('2026-09-22T05:00:00Z', $health->getLastSuccessAt());
        self::assertSame('archipelago:0.16.1', $health->getLastSuccessImage());
        self::assertSame('sha256:new', $health->getLastSuccessImageId());
    }

    public function testTheSameVerdictIsNeverCountedTwice(): void
    {
        // The reconciliation reads the same verdict every five minutes.
        $health = ApworldHealth::start('h-1', 'game-1', 'hash-1');
        $health->recordVerdict('failed', '2026-09-20T05:00:00Z', null, null);

        self::assertFalse($health->recordVerdict('failed', '2026-09-20T05:00:00Z', null, null));

        self::assertSame(1, $health->getConsecutiveFailures());
    }

    public function testAHashThatNeverPassedHasNoSuccess(): void
    {
        $health = ApworldHealth::start('h-1', 'game-1', 'hash-1');
        $health->recordVerdict('failed', '2026-09-20T05:00:00Z', null, null);

        self::assertFalse($health->hasPassedBefore());
        self::assertNull($health->getLastSuccessImage());
    }
}
