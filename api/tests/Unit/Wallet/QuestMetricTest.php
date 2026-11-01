<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Application\Support\QuestAnnouncement;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
use PHPUnit\Framework\TestCase;

/** Story 41.64: every metric is explained to the admin, and time played reads in hours. */
final class QuestMetricTest extends TestCase
{
    public function testEveryMetricHasALabelAUnitAndAHelpLine(): void
    {
        foreach (QuestMetric::cases() as $metric) {
            self::assertNotSame('', $metric->label());
            self::assertNotSame('', $metric->unit());
            self::assertNotSame('', $metric->help());
        }
    }

    public function testOnlyTimePlayedIsADuration(): void
    {
        self::assertSame([QuestMetric::PlayMinutes], array_values(array_filter(QuestMetric::cases(), static fn (QuestMetric $metric): bool => $metric->isDuration())));
    }

    public function testSuperlativesAimOnlyAtAnEventAndCommunityMetricsAtNothing(): void
    {
        self::assertSame(['event'], QuestMetric::Superlatives->scopes());
        self::assertSame(['game', 'event', 'platform', 'recent'], QuestMetric::PlayMinutes->scopes());
        self::assertSame([], QuestMetric::BountiesWon->scopes());

        new QuestObjective(QuestMetric::Superlatives, 1, QuestObjective::SCOPE_EVENT, 'lan-3');
        $this->expectException(\DomainException::class);
        new QuestObjective(QuestMetric::Superlatives, 1, QuestObjective::SCOPE_GAME, 'hk');
    }

    public function testAPlatformTargetNamesItsFamiliesInOneOrder(): void
    {
        $objective = new QuestObjective(QuestMetric::Goals, 3, QuestObjective::SCOPE_PLATFORM, 'Nintendo 64| Super Nintendo |Nintendo 64');

        self::assertSame(['Nintendo 64', 'Super Nintendo'], $objective->families());
        self::assertSame('jeu Nintendo 64 ou Super Nintendo', $objective->selfName());
        self::assertSame('jeu Switch', new QuestObjective(QuestMetric::Goals, 1, QuestObjective::SCOPE_PLATFORM, 'Switch')->selfName());
        self::assertSame('3 goals sur un jeu Nintendo 64 ou Super Nintendo', QuestAnnouncement::describe($objective, null));
    }

    public function testARecentTargetTakesSevenToNinetyDays(): void
    {
        $objective = new QuestObjective(QuestMetric::Sessions, 1, QuestObjective::SCOPE_RECENT, '30');

        self::assertSame(30, $objective->recentDays());
        self::assertSame('jeu mis en ligne ces 30 derniers jours', $objective->selfName());
        self::assertNull(new QuestObjective(QuestMetric::Sessions, 1, QuestObjective::SCOPE_GAME, 'hk')->selfName());
        foreach (['6', '91', 'trente', ''] as $days) {
            try {
                new QuestObjective(QuestMetric::Sessions, 1, QuestObjective::SCOPE_RECENT, $days);
                self::fail(sprintf('%s days is refused', $days));
            } catch (\DomainException) {
            }
        }
        $this->expectException(\DomainException::class);
        new QuestObjective(QuestMetric::Goals, 1, QuestObjective::SCOPE_PLATFORM, ' | ');
    }

    public function testMinutesReadAsHours(): void
    {
        self::assertSame('6 h', QuestMetric::hours(360));
        self::assertSame('2 h 30', QuestMetric::hours(150));
        self::assertSame('1 h 05', QuestMetric::hours(65));
        self::assertSame('45 min', QuestMetric::hours(45));
        self::assertSame('0 min', QuestMetric::hours(-3));
    }

    public function testAnAmountReadsWithItsUnit(): void
    {
        self::assertSame('1 goal', QuestMetric::Goals->describe(1));
        self::assertSame('3 nouveaux jeux', QuestMetric::NewGames->describe(3));
        self::assertSame('2 h 30 de jeu', QuestMetric::PlayMinutes->describe(150));
    }
}
