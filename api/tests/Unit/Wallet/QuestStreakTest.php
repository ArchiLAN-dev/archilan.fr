<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Application\Query\MyWeeklyQuests;
use App\Wallet\Domain\ValueObject\QuestWeek;
use PHPUnit\Framework\TestCase;

/**
 * Story 41.34: the run of weeks with the chest opened, as the quests panel shows it.
 */
final class QuestStreakTest extends TestCase
{
    public function testTheRunStandsOnTheWeeksBeforeUntilThisWeeksChestIsOpened(): void
    {
        $now = QuestWeek::containing(new \DateTimeImmutable('2026-10-14T12:00:00+00:00'));

        self::assertSame(['current' => 2, 'best' => 2, 'thisWeek' => false], MyWeeklyQuests::streak(['2026-W41', '2026-W40'], $now));
    }

    public function testThisWeeksChestExtendsTheRun(): void
    {
        $now = QuestWeek::containing(new \DateTimeImmutable('2026-10-14T12:00:00+00:00'));

        self::assertSame(['current' => 3, 'best' => 3, 'thisWeek' => true], MyWeeklyQuests::streak(['2026-W40', '2026-W42', '2026-W41'], $now));
    }

    public function testAMissedWeekBreaksTheRunButKeepsTheRecord(): void
    {
        $now = QuestWeek::containing(new \DateTimeImmutable('2026-10-14T12:00:00+00:00'));

        self::assertSame(['current' => 0, 'best' => 3, 'thisWeek' => false], MyWeeklyQuests::streak(['2026-W37', '2026-W38', '2026-W39'], $now));
        self::assertSame(['current' => 1, 'best' => 3, 'thisWeek' => true], MyWeeklyQuests::streak(['2026-W37', '2026-W38', '2026-W39', '2026-W42'], $now));
    }

    public function testTheRunCrossesTheNewYear(): void
    {
        $now = QuestWeek::containing(new \DateTimeImmutable('2027-01-06T12:00:00+00:00'));

        self::assertSame('2027-W01', $now->key);
        self::assertSame(['current' => 3, 'best' => 3, 'thisWeek' => true], MyWeeklyQuests::streak(['2026-W52', '2026-W53', '2027-W01'], $now));
    }

    public function testNoChestNoRun(): void
    {
        $now = QuestWeek::containing(new \DateTimeImmutable('2026-10-14T12:00:00+00:00'));

        self::assertSame(['current' => 0, 'best' => 0, 'thisWeek' => false], MyWeeklyQuests::streak([], $now));
    }
}
