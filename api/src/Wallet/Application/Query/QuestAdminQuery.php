<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Application\Support\QuestCalendar;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;

/**
 * The admin page of the weekly quests (story 41.15): the catalog of objective types, every quest, the number a
 * week, and the current and coming weeks with what each serves. The current week is frozen first, so the page shows
 * the quests the members see.
 */
final readonly class QuestAdminQuery
{
    public function __construct(
        private QuestRepositoryInterface $quests,
        private QuestWeekPlanner $planner,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{
     *   questsPerWeek: int,
     *   metrics: list<array{key: string, label: string, unit: string, unitOne: string}>,
     *   quests: list<array{id: string, title: string, description: string, reward: int, objectives: list<array{metric: string, target: int}>, inDraw: bool, retired: bool, createdAt: string}>,
     *   weeks: list<array{key: string, startsAt: string, endsAt: string, current: bool, drawn: bool, quests: list<array{questId: string, title: string, reward: int, origin: string, retired: bool}>}>
     * }
     */
    public function overview(): array
    {
        $now = $this->clock->now();
        $this->planner->served(QuestWeek::containing($now));

        $all = $this->quests->allQuests();
        $byId = [];
        foreach ($all as $quest) {
            $byId[$quest->getId()] = $quest;
        }

        $weeks = QuestCalendar::plannable($now);
        $keys = array_map(static fn (QuestWeek $week): string => $week->key, $weeks);
        $entries = $this->quests->entriesOfWeeks($keys);
        $drawn = $this->quests->drawnWeeks($keys);

        $calendar = [];
        foreach ($weeks as $index => $week) {
            $served = [];
            foreach ($entries[$week->key] ?? [] as $entry) {
                $quest = $byId[$entry->getQuestId()] ?? null;
                if (null === $quest) {
                    continue;
                }
                $served[] = [
                    'questId' => $quest->getId(),
                    'title' => $quest->getTitle(),
                    'reward' => $quest->getReward(),
                    'origin' => $entry->getOrigin()->value,
                    'retired' => $quest->isRetired(),
                ];
            }
            $calendar[] = [
                'key' => $week->key,
                'startsAt' => $week->start->format(\DATE_ATOM),
                'endsAt' => $week->end->format(\DATE_ATOM),
                'current' => 0 === $index,
                'drawn' => \in_array($week->key, $drawn, true),
                'quests' => $served,
            ];
        }

        return [
            'questsPerWeek' => $this->quests->questsPerWeek(),
            'metrics' => array_map(
                static fn (QuestMetric $metric): array => ['key' => $metric->value, 'label' => $metric->label(), 'unit' => $metric->unit(), 'unitOne' => $metric->unitOne()],
                QuestMetric::cases(),
            ),
            'quests' => array_map(static fn (QuestDefinition $quest): array => [
                'id' => $quest->getId(),
                'title' => $quest->getTitle(),
                'description' => $quest->getDescription(),
                'reward' => $quest->getReward(),
                'objectives' => array_map(static fn (QuestObjective $objective): array => $objective->toArray(), $quest->getObjectives()),
                'inDraw' => $quest->isInDraw(),
                'retired' => $quest->isRetired(),
                'createdAt' => $quest->getCreatedAt()->format(\DATE_ATOM),
            ], $all),
            'weeks' => $calendar,
        ];
    }
}
