<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Community\Application\Support\CosmeticRewardCatalog;
use App\Community\Application\Support\QuestMetricProvider;
use App\Community\Domain\ValueObject\CosmeticReward;
use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;

/**
 * The quests of the week as the wallet page shows them (story 41.6): what each pays, whether it is done, whether it
 * is already paid (the hourly run pays it within the hour), and when the quests renew. Story 41.15: the quests the
 * week serves, each objective with where the member stands. Story 41.16: the chest for doing them all. Story 41.17: the weeks before.
 * Story 41.34: the run of weeks with the chest opened.
 */
final readonly class MyWeeklyQuests
{
    public const int HISTORY_WEEKS = 4;

    public function __construct(
        private WeeklyQuestsQueryInterface $quests,
        private QuestWeekPlanner $planner,
        private QuestRepositoryInterface $settings,
        private ClockInterface $clock,
        private CosmeticRewardCatalog $cosmetics,
    ) {
    }

    /**
     * @return array{week: string, renewsAt: string, quests: list<array{key: string, label: string, description: string, reward: int, done: bool, paid: bool, objectives: list<array{metric: string, label: string, unit: string, target: int, current: int, scope: string|null}>, cosmetic: array{type: string, key: string, label: string}|null}>, chest: array{reward: int, done: int, total: int, paid: bool}|null, history: list<array{week: string, startsAt: string, endsAt: string, done: int, served: int, chest: bool, pelles: int}>, streak: array{current: int, best: int, thisWeek: bool}}
     */
    public function of(string $userId): array
    {
        $week = QuestWeek::containing($this->clock->now());
        $served = $this->planner->served($week);
        $counts = [] === $served ? [] : $this->quests->counts($week, QuestDefinition::objectivesOf($served), $userId);
        $scopes = $this->scopeNames($served);
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
                    'unit' => $objective->metric->unitFor($objective->target),
                    'target' => $objective->target,
                    'current' => $mine[$objective->key()] ?? 0,
                    // Story 41.18: the game or event the objective aims at, by name.
                    'scope' => $scopes[$objective->key()] ?? null,
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
                // Story 41.28: the cosmetic it unlocks, besides the pelles.
                'cosmetic' => $this->cosmetic($quest),
            ];
        }

        $chestReward = $this->settings->chestReward();
        $chest = 0 === $chestReward || [] === $quests ? null : [
            'reward' => $chestReward,
            'done' => \count(array_filter($quests, static fn (array $quest): bool => $quest['done'])),
            'total' => \count($quests),
            'paid' => $this->quests->chestPaid($userId, $week),
        ];

        $earned = $this->quests->earnedBy($userId);
        $chestWeeks = array_keys(array_filter($earned, static fn (array $mine): bool => $mine['chest']));

        return [
            'week' => $week->key,
            'renewsAt' => $week->end->format(\DATE_ATOM),
            'quests' => $quests,
            'chest' => $chest,
            'history' => $this->history($week, $earned),
            'streak' => self::streak($chestWeeks, $week),
        ];
    }

    /**
     * Story 41.34: the weeks in a row with the chest opened. The week in progress counts once its chest is open;
     * until then the run still stands on the weeks before, so it is not shown broken on a Monday morning.
     * `best` is the longest run ever, as the achievement counts it (story 41.17).
     *
     * @param list<string> $chestWeeks the keys of the weeks whose chest was opened (`2026-W41`)
     *
     * @return array{current: int, best: int, thisWeek: bool}
     */
    public static function streak(array $chestWeeks, QuestWeek $current): array
    {
        $opened = array_flip($chestWeeks);
        $thisWeek = isset($opened[$current->key]);

        $run = $thisWeek ? 1 : 0;
        for ($week = $current->previous(); isset($opened[$week->key]); $week = $week->previous()) {
            ++$run;
        }

        return ['current' => $run, 'best' => QuestMetricProvider::longestStreak($chestWeeks), 'thisWeek' => $thisWeek];
    }

    /**
     * Story 41.18: the name of the game or event each aimed objective of these quests counts.
     *
     * @param list<QuestDefinition> $quests
     *
     * @return array<string, string> objective key => name
     */
    private function scopeNames(array $quests): array
    {
        $aimed = array_filter(QuestDefinition::objectivesOf($quests), static fn (QuestObjective $objective): bool => null !== $objective->scope);
        if ([] === $aimed) {
            return [];
        }
        $options = $this->quests->scopeOptions();
        $names = [
            QuestObjective::SCOPE_GAME => array_column($options['games'], 'name', 'id'),
            QuestObjective::SCOPE_EVENT => array_column($options['events'], 'title', 'id'),
        ];

        $scopes = [];
        foreach ($aimed as $objective) {
            $scopes[$objective->key()] = $names[(string) $objective->scope][(string) $objective->scopeId] ?? 'cible retirée';
        }

        return $scopes;
    }

    /**
     * Story 41.17: the member's 4 weeks before this one - quests done out of those served, chest, pelles earned.
     * A week paid before story 41.15 served nothing on record: it counts the quests it paid.
     *
     * @param array<string, array{quests: list<string>, pelles: int, chest: bool}> $earned
     *
     * @return list<array{week: string, startsAt: string, endsAt: string, done: int, served: int, chest: bool, pelles: int}>
     */
    private function history(QuestWeek $current, array $earned): array
    {
        $weeks = [];
        $week = $current;
        for ($i = 0; $i < self::HISTORY_WEEKS; ++$i) {
            $week = $week->previous();
            $weeks[] = $week;
        }
        $entries = $this->settings->entriesOfWeeks(array_map(static fn (QuestWeek $week): string => $week->key, $weeks));

        return array_map(static function (QuestWeek $week) use ($entries, $earned): array {
            $mine = $earned[$week->key] ?? ['quests' => [], 'pelles' => 0, 'chest' => false];
            $served = array_map(static fn ($entry): string => $entry->getQuestId(), $entries[$week->key] ?? []);

            return [
                'week' => $week->key,
                'startsAt' => $week->start->format(\DATE_ATOM),
                'endsAt' => $week->end->format(\DATE_ATOM),
                'done' => \count($mine['quests']),
                'served' => max(\count($served), \count($mine['quests'])),
                'chest' => $mine['chest'],
                'pelles' => $mine['pelles'],
            ];
        }, $weeks);
    }

    /**
     * Story 41.28: the cosmetic a quest unlocks, in words.
     *
     * @return array{type: string, key: string, label: string}|null
     */
    private function cosmetic(QuestDefinition $quest): ?array
    {
        $cosmetic = $quest->getCosmetic();
        try {
            $reward = null === $cosmetic ? null : CosmeticReward::fromParts($cosmetic['type'], $cosmetic['key']);
        } catch (\DomainException) {
            return null;
        }

        return null === $reward ? null : [...$reward->toArray(), 'label' => $this->cosmetics->label($reward)];
    }
}
