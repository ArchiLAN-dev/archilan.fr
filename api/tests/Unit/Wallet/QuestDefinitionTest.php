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
        self::assertSame([QuestMetric::Goals, QuestMetric::Checks], QuestDefinition::metricsOf([$quest, $quest]));
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
        $quest->edit('Autre', '', 10, [new QuestObjective(QuestMetric::Sessions, 3)], false);
        self::assertFalse($quest->isDrawable());
    }

    public function testTheDrawFillsTheWeekAroundItsPinnedQuestsWithoutRepeats(): void
    {
        $randomizer = new Randomizer(new Mt19937(41));
        $candidates = ['a', 'b', 'c', 'd', 'e'];

        $drawn = QuestDraw::draw(['b'], $candidates, 3, $randomizer);
        self::assertCount(2, $drawn);
        self::assertNotContains('b', $drawn);
        self::assertSame($drawn, array_values(array_unique($drawn)));

        self::assertSame([], QuestDraw::draw(['x', 'y', 'z'], $candidates, 3, $randomizer), 'pinned quests fill the week');
        self::assertCount(1, QuestDraw::draw([], ['a'], 3, $randomizer), 'not enough quests: fewer that week');
    }

    /**
     * @param list<QuestObjective> $objectives
     */
    private function quest(array $objectives, string $title = 'Quête', int $reward = 40): QuestDefinition
    {
        return QuestDefinition::write($title, '', $reward, $objectives, true, new \DateTimeImmutable('2026-10-05'));
    }
}
