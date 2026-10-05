<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;

/**
 * The quests of the week as the wallet page shows them (story 41.6): what each pays, whether it is done, whether it
 * is already paid (the hourly run pays it within the hour), and when the quests renew. Story 41.15: the quests the
 * week serves, each objective with where the member stands.
 */
final readonly class MyWeeklyQuests
{
    public function __construct(
        private WeeklyQuestsQueryInterface $quests,
        private QuestWeekPlanner $planner,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{week: string, renewsAt: string, quests: list<array{key: string, label: string, description: string, reward: int, done: bool, paid: bool, objectives: list<array{metric: string, label: string, unit: string, target: int, current: int}>}>}
     */
    public function of(string $userId): array
    {
        $week = QuestWeek::containing($this->clock->now());
        $served = $this->planner->served($week);
        $counts = [] === $served ? [] : $this->quests->counts($week, QuestDefinition::metricsOf($served), $userId);
        $mine = array_map(static fn (array $byMember): int => $byMember[$userId] ?? 0, $counts);
        $paid = $this->quests->rewardedQuests($userId, $week);

        $quests = [];
        foreach ($served as $quest) {
            $isPaid = \in_array($quest->getId(), $paid, true);
            $objectives = [];
            foreach ($quest->getObjectives() as $objective) {
                $objectives[] = [
                    'metric' => $objective->metric->value,
                    'label' => $objective->metric->label(),
                    'unit' => $objective->metric->unit(),
                    'target' => $objective->target,
                    'current' => $mine[$objective->metric->value] ?? 0,
                ];
            }
            $quests[] = [
                'key' => $quest->getId(),
                'label' => $quest->getTitle(),
                'description' => $quest->getDescription(),
                'reward' => $quest->getReward(),
                'done' => $isPaid || $quest->isAccomplishedWith($mine),
                'paid' => $isPaid,
                'objectives' => $objectives,
            ];
        }

        return ['week' => $week->key, 'renewsAt' => $week->end->format(\DATE_ATOM), 'quests' => $quests];
    }
}
