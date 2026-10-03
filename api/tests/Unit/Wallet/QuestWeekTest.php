<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Domain\Enum\WeeklyQuest;
use App\Wallet\Domain\ValueObject\QuestWeek;
use PHPUnit\Framework\TestCase;

/**
 * Story 41.6: the week of the quests is Paris's, and the three quests make 100 pelles at most.
 */
final class QuestWeekTest extends TestCase
{
    public function testTheWeekStartsOnMondayMidnightInParis(): void
    {
        // Sunday 23:30 UTC is already Monday 01:30 in Paris (summer time).
        $week = QuestWeek::containing(new \DateTimeImmutable('2026-10-04T23:30:00+00:00'));

        self::assertSame('2026-W41', $week->key);
        self::assertSame('2026-10-04T22:00:00+00:00', $week->start->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('2026-10-11T22:00:00+00:00', $week->end->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('2026-W40', $week->previous()->key);
    }

    public function testTheQuestsOfAWeekMakeAHundredPelles(): void
    {
        self::assertSame(100, array_sum(array_map(static fn (WeeklyQuest $q): int => $q->reward(), WeeklyQuest::cases())));
    }
}
