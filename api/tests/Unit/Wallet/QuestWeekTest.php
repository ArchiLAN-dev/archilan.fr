<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Domain\ValueObject\QuestWeek;
use PHPUnit\Framework\TestCase;

/**
 * Story 41.6: the week of the quests is Paris's. Story 41.15: a week is found back from its key.
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

    public function testAWeekIsFoundBackFromItsKey(): void
    {
        $week = QuestWeek::fromKey('2026-W41');

        self::assertNotNull($week);
        self::assertSame('2026-10-04T22:00:00+00:00', $week->start->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        self::assertSame('2026-W42', $week->next()->key);
        $last = QuestWeek::fromKey('2026-W53');
        self::assertNotNull($last, '2026 has 53 weeks');
        self::assertSame('2027-W01', $last->next()->key);
        self::assertNull(QuestWeek::fromKey('2025-W53'), '2025 has 52');
        self::assertNull(QuestWeek::fromKey('2026-41'));
        self::assertNull(QuestWeek::fromKey('2026-W54'));
    }
}
