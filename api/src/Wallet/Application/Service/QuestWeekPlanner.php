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
        if ($week->start <= $now && [] === $this->quests->drawnWeeks([$week->key])) {
            // Story 41.17: no quest to draw yet, no draw - the week stays open until a quest can come out, rather
            // than being frozen empty before the admins wrote any.
            $candidates = $this->candidates();
            if ([] !== $candidates && $this->quests->claimDraw($week->key, $now)) {
                $this->draw($week, $candidates, $now);
            }
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

    /** @return array<string, int> the quests a draw may pick, with their weight (story 41.18) */
    private function candidates(): array
    {
        $candidates = [];
        foreach ($this->quests->allQuests() as $quest) {
            if ($quest->isDrawable()) {
                $candidates[$quest->getId()] = $quest->getDrawWeight();
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, int> $candidates
     */
    private function draw(QuestWeek $week, array $candidates, \DateTimeImmutable $now): void
    {
        $previous = $week->previous()->key;
        $weeks = $this->quests->entriesOfWeeks([$week->key, $previous]);
        $entries = $weeks[$week->key] ?? [];
        $pinned = array_map(static fn (QuestWeekEntry $entry): string => $entry->getQuestId(), $entries);
        // Story 41.18: the week before's quests come last, so a quest does not come back two weeks in a row.
        $recent = array_map(static fn (QuestWeekEntry $entry): string => $entry->getQuestId(), $weeks[$previous] ?? []);

        $position = [] === $entries ? 0 : max(array_map(static fn (QuestWeekEntry $entry): int => $entry->getPosition(), $entries)) + 1;
        foreach (QuestDraw::draw($pinned, $candidates, $this->quests->questsPerWeek(), $this->randomizer, $recent) as $questId) {
            $this->quests->saveEntry(QuestWeekEntry::serve($week->key, $questId, QuestWeekOrigin::Drawn, $position++, $now));
        }
    }
}
