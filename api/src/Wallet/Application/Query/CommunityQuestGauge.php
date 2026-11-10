<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Application\Support\QuestAnnouncement;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;
use App\Wallet\Domain\ValueObject\ServedQuest;
use Psr\Clock\ClockInterface;

/**
 * Story 41.69: the public gauge of the week's collective quest, on the community page - no member named. It reads
 * the total the hourly run of the quests last counted and kept: a public read never draws the week nor counts what
 * was played. Until the week's first run, there is nothing to show.
 */
final readonly class CommunityQuestGauge
{
    public function __construct(
        private QuestRepositoryInterface $quests,
        private WeeklyQuestsQueryInterface $games,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{title: string, description: string, objective: string, reward: int, target: int, total: int, unit: string, duration: bool, reached: bool, renewsAt: string, countedAt: string}|null
     */
    public function current(): ?array
    {
        $week = QuestWeek::containing($this->clock->now());
        foreach ($this->quests->entriesOfWeeks([$week->key])[$week->key] ?? [] as $entry) {
            $quest = $this->quests->findQuest($entry->getQuestId());
            $total = $entry->getGroupTotal();
            $countedAt = $entry->getGroupCountedAt();
            if (null === $quest || !$quest->isCollective() || !$entry->isFrozen() || null === $total || null === $countedAt) {
                continue;
            }
            $objective = ServedQuest::of($quest, $entry)->getObjectives()[0] ?? null;
            if (null === $objective) {
                return null;
            }

            $scopeName = $this->scopeName($objective);

            return [
                'title' => $quest->getTitle(),
                'description' => $quest->getDescription(),
                'objective' => QuestAnnouncement::describe($objective, $scopeName),
                'reward' => $entry->getReward() ?? $quest->getReward(),
                'target' => $objective->target,
                'total' => $total,
                // Story 41.72: a counter counts in its label.
                'unit' => QuestObjective::SCOPE_COUNTER === $objective->scope ? ($scopeName ?? 'compteur supprimé') : $objective->metric->unitFor($objective->target),
                'duration' => $objective->metric->isDuration(),
                'reached' => $total >= $objective->target,
                'renewsAt' => $week->end->format(\DATE_ATOM),
                'countedAt' => $countedAt->format(\DATE_ATOM),
            ];
        }

        return null;
    }

    private function scopeName(QuestObjective $objective): ?string
    {
        return match ($objective->scope) {
            QuestObjective::SCOPE_GAME => $this->games->gamesNamed([(string) $objective->scopeId])[(string) $objective->scopeId]['name'] ?? null,
            QuestObjective::SCOPE_EVENT => array_column($this->games->scopeOptions()['events'], 'title', 'id')[(string) $objective->scopeId] ?? null,
            QuestObjective::SCOPE_COUNTER => array_column($this->games->scopeOptions()['counters'], 'label', 'id')[(string) $objective->scopeId] ?? null,
            default => null,
        };
    }
}
