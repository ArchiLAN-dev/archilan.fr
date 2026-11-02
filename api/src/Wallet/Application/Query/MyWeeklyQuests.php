<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Community\Application\Support\CosmeticRewardCatalog;
use App\Community\Application\Support\QuestMetricProvider;
use App\Community\Domain\ValueObject\CosmeticReward;
use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use App\Wallet\Domain\ValueObject\ServedQuest;
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
     * @return array{week: string, renewsAt: string, quests: list<array{key: string, label: string, description: string, reward: int, tiers: list<array{target: int, reward: int, reached: bool, paid: bool}>|null, done: bool, paid: bool, objectives: list<array{metric: string, label: string, unit: string, target: int, current: int, scope: string|null, gameSlug: string|null, duration: bool}>, cosmetic: array{type: string, key: string, label: string}|null}>, chest: array{reward: int, done: int, total: int, paid: bool}|null, history: list<array{week: string, startsAt: string, endsAt: string, done: int, served: int, chest: bool, pelles: int}>, streak: array{current: int, best: int, thisWeek: bool}}
     */
    public function of(string $userId): array
    {
        $week = QuestWeek::containing($this->clock->now());
        $served = $this->planner->served($week);
        $counts = [] === $served ? [] : $this->quests->counts($week, ServedQuest::objectivesOf($served), $userId);
        [$scopes, $slugs] = $this->scopeNames($served);
        $mine = array_map(static fn (array $byMember): int => $byMember[$userId] ?? 0, $counts);
        $paid = $this->quests->rewardedQuests($userId, $week);
        $paidTiers = [] === array_filter($served, static fn (ServedQuest $quest): bool => [] !== $quest->tiers) ? [] : $this->quests->rewardedTiers($userId, $week);

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
                    // Story 41.67: the game aimed at (drawn or named), to link its page.
                    'gameSlug' => $slugs[$objective->key()] ?? null,
                    // Story 41.64: target and current are minutes, shown in hours.
                    'duration' => $objective->metric->isDuration(),
                ];
            }
            $quests[] = [
                'key' => $quest->getId(),
                'label' => $quest->getTitle(),
                'description' => $quest->getDescription(),
                'reward' => $quest->getReward(),
                // Story 41.68: done from the first tier; each tier reached, and paid, on the same bar.
                'tiers' => $this->tiers($quest, $mine, $isPaid, $paidTiers[$quest->getId()] ?? []),
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
     * Story 41.18: the name of the game or event each aimed objective of these quests counts. Story 41.67: and the
     * slug of the game, drawn maybe among games nobody played yet.
     *
     * @param list<ServedQuest> $quests
     *
     * @return array{0: array<string, string>, 1: array<string, string>} objective key => name, objective key => game slug
     */
    private function scopeNames(array $quests): array
    {
        $aimed = array_filter(ServedQuest::objectivesOf($quests), static fn (QuestObjective $objective): bool => null !== $objective->scope);
        if ([] === $aimed) {
            return [[], []];
        }
        $games = $this->quests->gamesNamed(array_values(array_unique(array_map(
            static fn (QuestObjective $objective): string => (string) $objective->scopeId,
            array_filter($aimed, static fn (QuestObjective $objective): bool => QuestObjective::SCOPE_GAME === $objective->scope),
        ))));
        $events = array_column($this->quests->scopeOptions()['events'], 'title', 'id');

        $scopes = [];
        $slugs = [];
        foreach ($aimed as $objective) {
            $game = QuestObjective::SCOPE_GAME === $objective->scope ? ($games[(string) $objective->scopeId] ?? null) : null;
            if (null !== $game) {
                $slugs[$objective->key()] = $game['slug'];
            }
            // Story 41.66: a platform or new-release target names itself.
            $scopes[$objective->key()] = $objective->selfName() ?? $game['name'] ?? (QuestObjective::SCOPE_EVENT === $objective->scope ? $events[(string) $objective->scopeId] ?? null : null) ?? 'cible retirée';
        }

        return [$scopes, $slugs];
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
     * @param array<string, int> $mine      objective key => the member's count
     * @param list<int>          $paidTiers the tiers past the first already paid
     *
     * @return list<array{target: int, reward: int, reached: bool, paid: bool}>|null
     */
    private function tiers(ServedQuest $quest, array $mine, bool $isPaid, array $paidTiers): ?array
    {
        if ([] === $quest->tiers) {
            return null;
        }
        $reached = $quest->reachedTiers($mine);
        $tiers = [];
        foreach ($quest->tiers as $index => $tier) {
            $paid = 0 === $index ? $isPaid : \in_array($index + 1, $paidTiers, true);
            $tiers[] = ['target' => $tier->target, 'reward' => $tier->reward, 'reached' => $paid || \in_array($index + 1, $reached, true), 'paid' => $paid];
        }

        return $tiers;
    }

    /**
     * Story 41.28: the cosmetic a quest unlocks, in words.
     *
     * @return array{type: string, key: string, label: string}|null
     */
    private function cosmetic(ServedQuest $quest): ?array
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
