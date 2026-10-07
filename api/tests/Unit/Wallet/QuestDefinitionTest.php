<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\Service\QuestDraw;
use App\Wallet\Domain\ValueObject\QuestObjective;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Story 41.15: a quest is accomplished when every objective is reached; a week draws without repeats around its
 * pinned quests.
 */
final class QuestDefinitionTest extends TestCase
{
    public function testAQuestIsAccomplishedWhenEveryObjectiveIsReached(): void
    {
        $quest = $this->quest([new QuestObjective(QuestMetric::Goals, 2), new QuestObjective(QuestMetric::Checks, 50)]);

        self::assertFalse($quest->isAccomplishedWith(['goals' => 2, 'checks' => 49]));
        self::assertFalse($quest->isAccomplishedWith(['checks' => 80]));
        self::assertTrue($quest->isAccomplishedWith(['goals' => 3, 'checks' => 50]));
        self::assertSame(['goals', 'checks'], array_map(static fn (QuestObjective $objective): string => $objective->key(), QuestDefinition::objectivesOf([$quest, $quest])));
        self::assertSame('nouveau partenaire', QuestMetric::NewPartners->unitFor(1));
        self::assertSame('nouveaux partenaires', QuestMetric::NewPartners->unitFor(2));
    }

    public function testAQuestRefusesWhatItCannotPay(): void
    {
        foreach ([
            'quest_text_invalid' => fn () => $this->quest([new QuestObjective(QuestMetric::Goals, 1)], title: '  '),
            'quest_reward_invalid' => fn () => $this->quest([new QuestObjective(QuestMetric::Goals, 1)], reward: 1001),
            'quest_objectives_invalid' => fn () => $this->quest([]),
            'quest_objectives_duplicated' => fn () => $this->quest([new QuestObjective(QuestMetric::Goals, 1), new QuestObjective(QuestMetric::Goals, 2)]),
            'quest_objective_target_invalid' => static fn () => new QuestObjective(QuestMetric::Checks, 0),
            'quest_objective_scope_invalid' => static fn () => new QuestObjective(QuestMetric::Weeklies, 1, QuestObjective::SCOPE_GAME, 'g1'),
            'quest_weight_invalid' => fn () => $this->quest([new QuestObjective(QuestMetric::Goals, 1)], weight: 6),
        ] as $code => $build) {
            try {
                $build();
                self::fail($code);
            } catch (\DomainException $e) {
                self::assertSame($code, $e->getMessage());
            }
        }
    }

    public function testARetiredQuestOrOneOutOfTheDrawIsNotDrawable(): void
    {
        $quest = $this->quest([new QuestObjective(QuestMetric::Goals, 1)]);
        self::assertTrue($quest->isDrawable());

        $quest->retire(new \DateTimeImmutable('2026-10-05'));
        self::assertFalse($quest->isDrawable());
        $quest->restore();
        $quest->edit('Autre', '', 10, [new QuestObjective(QuestMetric::Sessions, 3)], false, 2);
        self::assertSame(2, $quest->getDrawWeight());
        self::assertFalse($quest->isDrawable());
    }

    public function testTheDrawFillsTheWeekAroundItsPinnedQuestsWithoutRepeats(): void
    {
        $randomizer = new Randomizer(new Mt19937(41));
        $candidates = ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1];

        $drawn = QuestDraw::draw(['b'], $candidates, 3, $randomizer);
        self::assertCount(2, $drawn);
        self::assertNotContains('b', $drawn);
        self::assertSame($drawn, array_values(array_unique($drawn)));

        self::assertSame([], QuestDraw::draw(['x', 'y', 'z'], $candidates, 3, $randomizer), 'pinned quests fill the week');
        self::assertCount(1, QuestDraw::draw([], ['a' => 1], 3, $randomizer), 'not enough quests: fewer that week');
    }

    public function testLastWeeksQuestsComeOnlyWhenTheOthersCannotFillTheWeek(): void
    {
        $randomizer = new Randomizer(new Mt19937(7));
        $candidates = ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1];

        for ($i = 0; $i < 20; ++$i) {
            $drawn = QuestDraw::draw([], $candidates, 2, $randomizer, ['a', 'b']);
            sort($drawn);
            self::assertSame(['c', 'd'], $drawn);
        }
        $drawn = QuestDraw::draw([], $candidates, 3, $randomizer, ['a', 'b']);
        self::assertCount(3, $drawn);
        self::assertContains('c', $drawn);
        self::assertContains('d', $drawn);
    }

    public function testAHeavierQuestComesOutMoreOften(): void
    {
        $randomizer = new Randomizer(new Mt19937(41));
        $first = ['light' => 0, 'heavy' => 0];
        for ($i = 0; $i < 2000; ++$i) {
            ++$first[QuestDraw::draw([], ['light' => 1, 'heavy' => 4], 1, $randomizer)[0]];
        }

        // 4 to 1: about 1600 out of 2000.
        self::assertGreaterThan(1450, $first['heavy']);
        self::assertLessThan(1750, $first['heavy']);
    }

    public function testAnObjectiveAimedAtAGameIsItsOwnCount(): void
    {
        $everywhere = new QuestObjective(QuestMetric::Goals, 1);
        $onGame = new QuestObjective(QuestMetric::Goals, 1, QuestObjective::SCOPE_GAME, 'hk');
        $quest = $this->quest([$everywhere, $onGame]);

        self::assertSame('goals@game:hk', $onGame->key());
        self::assertSame(['metric' => 'goals', 'target' => 1, 'scope' => 'game', 'scopeId' => 'hk'], $onGame->toArray());
        self::assertFalse($quest->isAccomplishedWith(['goals' => 3]));
        self::assertTrue($quest->isAccomplishedWith(['goals' => 3, 'goals@game:hk' => 1]));
        self::assertSame(['goals', 'goals@game:hk'], array_map(static fn (QuestObjective $objective): string => $objective->key(), $quest->getObjectives()), 'stored and read back');
    }

    /**
     * @param list<QuestObjective> $objectives
     */
    private function quest(array $objectives, string $title = 'Quête', int $reward = 40, int $weight = 1): QuestDefinition
    {
        return QuestDefinition::write($title, '', $reward, $objectives, true, $weight, new \DateTimeImmutable('2026-10-05'));
    }
}
