<?php

declare(strict_types=1);

namespace App\Wallet\Application\Service;

use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekEntry;
use App\Wallet\Domain\Enum\QuestWeekOrigin;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\Service\QuestDraw;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use App\Wallet\Domain\ValueObject\ServedQuest;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

/**
 * The quests a week serves (story 41.15). A week that has begun is frozen the first time it is needed - by the
 * wallet page or the hourly payment: its pinned quests, then a draw among the quests in the draw until the week has
 * the number set. The draw happens once; afterwards only an admin changes the week.
 *
 * Story 41.67: a quest is frozen in its week the first time the week in progress is needed (or when pinned to it):
 * its objectives and reward as they are then, and the game drawn for it when it aims at one. A quest whose pool has
 * no game for the week is left out of the draw, and out of the week if it was pinned there.
 */
final readonly class QuestWeekPlanner
{
    public function __construct(
        private QuestRepositoryInterface $quests,
        private ClockInterface $clock,
        private Randomizer $randomizer,
        private WeeklyQuestsQueryInterface $games,
    ) {
    }

    /**
     * @return list<ServedQuest> the week's quests, by position
     */
    public function served(QuestWeek $week): array
    {
        $now = $this->clock->now();
        if ($week->start <= $now && [] === $this->quests->drawnWeeks([$week->key])) {
            // Story 41.17: no quest to draw yet, no draw - the week stays open until a quest can come out, rather
            // than being frozen empty before the admins wrote any.
            $candidates = $this->candidates($week);
            if ([] !== $candidates && $this->quests->claimDraw($week->key, $now)) {
                $this->draw($week, $candidates, $now);
            }
        }

        $inProgress = $week->start <= $now && $now < $week->end;
        $served = [];
        foreach ($this->quests->entriesOfWeeks([$week->key])[$week->key] ?? [] as $entry) {
            $quest = $this->quests->findQuest($entry->getQuestId());
            if (null === $quest) {
                continue;
            }
            if (!$entry->isFrozen() && $inProgress && !$this->freeze($week, $quest, $entry)) {
                continue;
            }
            $served[] = ServedQuest::of($quest, $entry);
        }

        // Story 41.67: an objective still aiming at a game to draw has nothing to count - never served as such.
        return array_values(array_filter($served, static fn (ServedQuest $quest): bool => [] === array_filter(
            $quest->getObjectives(),
            static fn (QuestObjective $objective): bool => null !== $objective->pool(),
        )));
    }

    /** Story 41.67: whether the quest may be served that week - its pool, if it draws a game, has one. */
    public function canServe(QuestDefinition $quest, QuestWeek $week): bool
    {
        return null === $quest->drawPool() || [] !== $this->drawable($quest, $week);
    }

    /**
     * Story 41.67: freezes the quest in its week entry, drawing its game if it aims at one.
     *
     * @return bool false when its pool has no game for the week (nothing frozen)
     */
    public function freeze(QuestWeek $week, QuestDefinition $quest, QuestWeekEntry $entry): bool
    {
        $gameId = null;
        if (null !== $quest->drawPool()) {
            $games = $this->drawable($quest, $week);
            if ([] === $games) {
                return false;
            }
            // Not the game drawn the last time this quest was served, while another one is there.
            $last = $this->quests->lastDrawnGame($quest->getId(), $week->key);
            $fresh = array_values(array_diff($games, null === $last ? [] : [$last]));
            $pool = [] === $fresh ? $games : $fresh;
            $gameId = $pool[$this->randomizer->getInt(0, \count($pool) - 1)];
        }
        $entry->freeze(
            array_map(static fn (QuestObjective $objective): QuestObjective => null === $gameId ? $objective : $objective->drawnOn($gameId), $quest->getObjectives()),
            $quest->getReward(),
            $gameId,
        );
        $this->quests->saveEntry($entry);

        return true;
    }

    /**
     * The games the quest's game may be drawn from that week, those it already names left aside.
     *
     * @return list<string>
     */
    private function drawable(QuestDefinition $quest, QuestWeek $week): array
    {
        $pool = $quest->drawPool();

        return null === $pool ? [] : array_values(array_diff($this->games->drawableGames($pool, $week), $quest->aimedGames()));
    }

    /** @return array<string, int> the quests a draw may pick, with their weight (story 41.18) */
    private function candidates(QuestWeek $week): array
    {
        $candidates = [];
        foreach ($this->quests->allQuests() as $quest) {
            if ($quest->isDrawable() && $this->canServe($quest, $week)) {
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
