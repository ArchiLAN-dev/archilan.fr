<?php

declare(strict_types=1);

namespace App\Wallet\Application\Service;

use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekEntry;
use App\Wallet\Domain\Enum\QuestWeekOrigin;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\Service\QuestDraw;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

/**
 * The quests a week serves (story 41.15). A week that has begun is frozen the first time it is needed - by the
 * wallet page or the hourly payment: its pinned quests, then a draw among the quests in the draw until the week has
 * the number set. The draw happens once; afterwards only an admin changes the week.
 */
final readonly class QuestWeekPlanner
{
    public function __construct(
        private QuestRepositoryInterface $quests,
        private ClockInterface $clock,
        private Randomizer $randomizer,
    ) {
    }

    /**
     * @return list<QuestDefinition> the week's quests, by position
     */
    public function served(QuestWeek $week): array
    {
        $now = $this->clock->now();
        if ($week->start <= $now && $this->quests->claimDraw($week->key, $now)) {
            $this->draw($week, $now);
        }

        $served = [];
        foreach ($this->quests->entriesOfWeeks([$week->key])[$week->key] ?? [] as $entry) {
            $quest = $this->quests->findQuest($entry->getQuestId());
            if (null !== $quest) {
                $served[] = $quest;
            }
        }

        return $served;
    }

    private function draw(QuestWeek $week, \DateTimeImmutable $now): void
    {
        $entries = $this->quests->entriesOfWeeks([$week->key])[$week->key] ?? [];
        $pinned = array_map(static fn (QuestWeekEntry $entry): string => $entry->getQuestId(), $entries);
        $candidates = array_values(array_map(
            static fn (QuestDefinition $quest): string => $quest->getId(),
            array_filter($this->quests->allQuests(), static fn (QuestDefinition $quest): bool => $quest->isDrawable()),
        ));

        $position = [] === $entries ? 0 : max(array_map(static fn (QuestWeekEntry $entry): int => $entry->getPosition(), $entries)) + 1;
        foreach (QuestDraw::draw($pinned, $candidates, $this->quests->questsPerWeek(), $this->randomizer) as $questId) {
            $this->quests->saveEntry(QuestWeekEntry::serve($week->key, $questId, QuestWeekOrigin::Drawn, $position++, $now));
        }
    }
}
