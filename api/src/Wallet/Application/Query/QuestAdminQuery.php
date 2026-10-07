<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Community\Application\Support\CosmeticRewardCatalog;
use App\Community\Domain\ValueObject\CosmeticReward;
use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Application\Support\QuestCalendar;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Entity\QuestWeekEntry;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;

/**
 * The admin page of the weekly quests (story 41.15): the catalog of objective types, every quest, the number a
 * week, and the current and coming weeks with what each serves. The current week is frozen first, so the page shows
 * the quests the members see. Story 41.16: the chest, what each quest paid, and the weeks just past.
 *
 * @phpstan-type ServedQuest array{questId: string, title: string, reward: int, origin: string, retired: bool}
 */
final readonly class QuestAdminQuery
{
    public const int PAST_WEEKS = 4;

    public function __construct(
        private QuestRepositoryInterface $quests,
        private WeeklyQuestsQueryInterface $ledger,
        private QuestWeekPlanner $planner,
        private ClockInterface $clock,
        private QuestAnnouncementChannelInterface $discord,
        private CosmeticRewardCatalog $cosmetics,
    ) {
    }

    /**
     * @return array{
     *   questsPerWeek: int,
     *   discordEnabled: bool,
     *   chestReward: int,
     *   metrics: list<array{key: string, label: string, unit: string, unitOne: string, scopable: bool}>,
     *   scopes: array{games: list<array{id: string, name: string}>, events: list<array{id: string, title: string}>},
     *   quests: list<array{id: string, title: string, description: string, reward: int, objectives: list<array{metric: string, target: int, scope?: string, scopeId?: string}>, inDraw: bool, drawWeight: int, cosmetic: array{type: string, key: string, label: string}|null, retired: bool, createdAt: string, stats: array{weeksServed: int, lastWeek: string|null, lastMembers: int, pelles: int}}>,
     *   weeks: list<array{key: string, startsAt: string, endsAt: string, current: bool, drawn: bool, quests: list<ServedQuest>}>,
     *   pastWeeks: list<array{key: string, startsAt: string, endsAt: string, quests: list<array{questId: string, title: string, reward: int, origin: string, retired: bool, members: int, pelles: int}>, chests: int, pelles: int}>
     * }
     */
    public function overview(): array
    {
        $now = $this->clock->now();
        $current = QuestWeek::containing($now);
        $this->planner->served($current);

        $all = $this->quests->allQuests();
        $byId = [];
        foreach ($all as $quest) {
            $byId[$quest->getId()] = $quest;
        }

        $weeks = QuestCalendar::plannable($now);
        $past = [];
        $cursor = $current;
        for ($i = 0; $i < self::PAST_WEEKS; ++$i) {
            $cursor = $cursor->previous();
            $past[] = $cursor;
        }
        $keys = array_map(static fn (QuestWeek $week): string => $week->key, [...$weeks, ...$past]);
        $entries = $this->quests->entriesOfWeeks($keys);
        $drawn = $this->quests->drawnWeeks($keys);
        $payments = $this->ledger->payments();
        $servedWeeks = $this->quests->weeksServedByQuest();

        $calendar = [];
        foreach ($weeks as $index => $week) {
            $calendar[] = [
                ...$this->bounds($week),
                'current' => 0 === $index,
                'drawn' => \in_array($week->key, $drawn, true),
                'quests' => $this->served($entries[$week->key] ?? [], $byId),
            ];
        }

        $history = [];
        foreach ($past as $week) {
            $paid = $payments[$week->key] ?? ['quests' => [], 'chests' => 0, 'chestPelles' => 0];
            $served = $this->served($entries[$week->key] ?? [], $byId);
            // Weeks paid before story 41.15 served nothing on record: their quests come back from the ledger.
            foreach (array_keys($paid['quests']) as $questId) {
                $quest = $byId[$questId] ?? null;
                if (null !== $quest && !\in_array($questId, array_column($served, 'questId'), true)) {
                    $served[] = ['questId' => $questId, 'title' => $quest->getTitle(), 'reward' => $quest->getReward(), 'origin' => 'drawn', 'retired' => $quest->isRetired()];
                }
            }
            $quests = array_map(static fn (array $served): array => [
                ...$served,
                'members' => $paid['quests'][$served['questId']]['members'] ?? 0,
                'pelles' => $paid['quests'][$served['questId']]['pelles'] ?? 0,
            ], $served);
            $history[] = [
                ...$this->bounds($week),
                'quests' => $quests,
                'chests' => $paid['chests'],
                'pelles' => array_sum(array_column($quests, 'pelles')) + $paid['chestPelles'],
            ];
        }

        return [
            'questsPerWeek' => $this->quests->questsPerWeek(),
            // Story 41.26: whether the « Annoncer sur Discord » button has anywhere to post.
            'discordEnabled' => $this->discord->isEnabled(),
            'chestReward' => $this->quests->chestReward(),
            'metrics' => array_map(
                static fn (QuestMetric $metric): array => [
                    'key' => $metric->value,
                    'label' => $metric->label(),
                    'unit' => $metric->unit(),
                    'unitOne' => $metric->unitOne(),
                    // Story 41.18: whether an objective of this type may aim at a game or an event.
                    'scopable' => \in_array($metric, QuestObjective::SCOPABLE, true),
                ],
                QuestMetric::cases(),
            ),
            'quests' => array_map(fn (QuestDefinition $quest): array => [
                'id' => $quest->getId(),
                'title' => $quest->getTitle(),
                'description' => $quest->getDescription(),
                'reward' => $quest->getReward(),
                'objectives' => array_map(static fn (QuestObjective $objective): array => $objective->toArray(), $quest->getObjectives()),
                'inDraw' => $quest->isInDraw(),
                'drawWeight' => $quest->getDrawWeight(),
                // Story 41.28: the cosmetic it unlocks.
                'cosmetic' => $this->cosmetic($quest),
                'retired' => $quest->isRetired(),
                'createdAt' => $quest->getCreatedAt()->format(\DATE_ATOM),
                'stats' => $this->stats($servedWeeks[$quest->getId()] ?? [], $quest->getId(), $current, $payments),
            ], $all),
            'weeks' => $calendar,
            'pastWeeks' => $history,
            'scopes' => $this->ledger->scopeOptions(),
        ];
    }

    /**
     * How a quest did: the weeks it was served in (up to the current one), the members who accomplished it the
     * last time it was served before this week, and the pelles it paid in all.
     *
     * @param list<string>                                                                                                 $weeks    the weeks the quest was served in
     * @param array<string, array{quests: array<string, array{members: int, pelles: int}>, chests: int, chestPelles: int}> $payments
     *
     * @return array{weeksServed: int, lastWeek: string|null, lastMembers: int, pelles: int}
     */
    private function stats(array $weeks, string $questId, QuestWeek $current, array $payments): array
    {
        // Week keys (`2026-W41`) sort as their weeks do.
        $served = array_values(array_filter(
            $weeks,
            static fn (string $week): bool => $week <= $current->key,
        ));
        $finished = array_values(array_filter($served, static fn (string $week): bool => $week < $current->key));
        $lastWeek = [] === $finished ? null : $finished[\count($finished) - 1];

        return [
            'weeksServed' => \count($served),
            'lastWeek' => $lastWeek,
            'lastMembers' => null === $lastWeek ? 0 : ($payments[$lastWeek]['quests'][$questId]['members'] ?? 0),
            'pelles' => array_sum(array_map(static fn (array $week): int => $week['quests'][$questId]['pelles'] ?? 0, $payments)),
        ];
    }

    /** @return array{key: string, startsAt: string, endsAt: string} */
    private function bounds(QuestWeek $week): array
    {
        return ['key' => $week->key, 'startsAt' => $week->start->format(\DATE_ATOM), 'endsAt' => $week->end->format(\DATE_ATOM)];
    }

    /**
     * @param list<QuestWeekEntry>           $entries
     * @param array<string, QuestDefinition> $byId
     *
     * @return list<ServedQuest>
     */
    private function served(array $entries, array $byId): array
    {
        $served = [];
        foreach ($entries as $entry) {
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

        return $served;
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
