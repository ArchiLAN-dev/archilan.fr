<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Support\QuestMetricProvider;
use PHPUnit\Framework\TestCase;

/**
 * Story 41.17: the longest run of consecutive weeks with the chest, across year ends.
 */
final class QuestMetricProviderTest extends TestCase
{
    public function testTheLongestRunOfConsecutiveWeeksCounts(): void
    {
        self::assertSame(0, QuestMetricProvider::longestStreak([]));
        self::assertSame(1, QuestMetricProvider::longestStreak(['2026-W41']));
        self::assertSame(3, QuestMetricProvider::longestStreak(['2026-W38', '2026-W40', '2026-W41', '2026-W42', '2026-W38']), 'a gap breaks the run, a repeat counts once');
        self::assertSame(3, QuestMetricProvider::longestStreak(['2027-W01', '2026-W53', '2026-W52']), '2026 has 53 weeks');
        self::assertSame(2, QuestMetricProvider::longestStreak(['2025-W52', '2026-W01', 'nonsense']), '2025 has 52');
    }
}
