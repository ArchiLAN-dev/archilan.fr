<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Community\Application\Port\CosmeticOwnershipInterface;
use App\Community\Application\Support\CosmeticRewarder;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\ValueObject\CosmeticReward;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Message\AnnounceQuestsOnDiscordJob;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Pays the quests of the week (story 41.6). Each quest pays a member once a week (its ledger key names the week,
 * the quest and the member), so the hourly run can pass again and again. The week just ended is paid too, for a
 * quest done in its last hour. A banned or erased member earns nothing. Story 41.15: the quests are those the week
 * serves, accomplished when every objective is reached. Story 41.16: doing them all opens the week's chest.
 */
final readonly class AwardWeeklyQuests
{
    public function __construct(
        private WeeklyQuestsQueryInterface $quests,
        private QuestWeekPlanner $planner,
        private QuestRepositoryInterface $settings,
        private RecordPelleMovement $record,
        private Notifier $notifier,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
        private CosmeticRewarder $rewarder,
    ) {
    }

    /** @return int the number of quests (and chests) paid */
    public function award(): int
    {
        $current = QuestWeek::containing($this->clock->now());
        $paid = $this->awardWeek($current->previous()) + $this->awardWeek($current);
        $this->announce($current);

        return $paid;
    }

    /**
     * Story 41.17: tells the members who played lately (4 weeks before it) that the week's quests are out - once a
     * week, and only once it has quests. The week is marked announced before the notices go, so a failure halfway
     * never sends them twice.
     *
     * @return int the members notified
     */
    public function announce(QuestWeek $week): int
    {
        if ($this->settings->announcedWeek() === $week->key) {
            return 0;
        }
        $served = $this->planner->served($week);
        if ([] === $served) {
            return 0;
        }
        $this->settings->markAnnounced($week->key);

        $chest = $this->settings->chestReward();
        $payload = [
            'week' => $week->key,
            'count' => \count($served),
            'maxPelles' => array_sum(array_map(static fn (QuestDefinition $quest): int => $quest->getReward(), $served)) + $chest,
        ];
        $members = $this->quests->activeMembers($week->start->modify('-4 weeks'), $week->start);
        foreach ($members as $userId) {
            $this->notifier->notify($userId, Notification::TYPE_QUESTS_RENEWED, $payload);
        }
        // Story 41.24: and on Discord, from the worker - never in this hourly run.
        $this->messageBus->dispatch(new AnnounceQuestsOnDiscordJob($week->key));

        return \count($members);
    }

    public function awardWeek(QuestWeek $week): int
    {
        $served = $this->planner->served($week);
        if ([] === $served) {
            return 0;
        }
        $counts = $this->quests->counts($week, QuestDefinition::objectivesOf($served));

        $paid = 0;
        $doneAll = null;
        foreach ($served as $quest) {
            $accomplishers = $this->accomplishers($quest, $counts);
            // Story 41.16: the chest goes to those who accomplished every quest the week serves.
            $doneAll = null === $doneAll ? $accomplishers : array_values(array_intersect($doneAll, $accomplishers));
            $cosmetic = $this->cosmeticOf($quest);
            foreach ($accomplishers as $userId) {
                $newly = $this->pay(
                    $userId, $quest->getReward(), sprintf('Quête : %s', $quest->getTitle()),
                    sprintf('quest:%s:%s:%s', $week->key, $quest->getId(), $userId), sprintf('quête « %s »', $quest->getTitle()),
                );
                $paid += $newly;
                // Story 41.28: the cosmetic it unlocks, given once (the member keeps it when the quest comes back).
                if (1 === $newly && null !== $cosmetic) {
                    $this->rewarder->reward($userId, $cosmetic, CosmeticOwnershipInterface::SOURCE_QUEST, $quest->getTitle());
                }
            }
        }

        $chest = $this->settings->chestReward();
        if ($chest > 0) {
            foreach ($doneAll as $userId) {
                $paid += $this->pay($userId, $chest, 'Coffre de la semaine', sprintf('quest-chest:%s:%s', $week->key, $userId), 'coffre de la semaine');
            }
        }

        return $paid;
    }

    private function cosmeticOf(QuestDefinition $quest): ?CosmeticReward
    {
        $cosmetic = $quest->getCosmetic();
        try {
            return null === $cosmetic ? null : CosmeticReward::fromParts($cosmetic['type'], $cosmetic['key']);
        } catch (\DomainException) {
            return null;
        }
    }

    /** @return int 1 when paid now, 0 when already paid or the member cannot earn */
    private function pay(string $userId, int $amount, string $label, string $key, string $reason): int
    {
        try {
            $recorded = $this->record->record(new RecordPelleMovementInput($userId, $amount, PelleKind::Gold, null, PelleReason::QuestReward, $label, null, $key));
        } catch (ForbiddenException|NotFoundException) {
            return 0;
        }
        if ($recorded->alreadyRecorded) {
            return 0;
        }
        $this->notifier->notify($userId, Notification::TYPE_PELLES_ADJUSTED, ['amount' => $amount, 'kind' => PelleKind::Gold->value, 'reason' => $reason]);

        return 1;
    }

    /**
     * The members who reached every objective: those far enough on the first one, kept if they are on the others.
     *
     * @param array<string, array<string, int>> $counts objective key => member => count
     *
     * @return list<string>
     */
    private function accomplishers(QuestDefinition $quest, array $counts): array
    {
        $objectives = $quest->getObjectives();
        if ([] === $objectives) {
            return [];
        }
        $first = $objectives[0];
        $candidates = array_keys(array_filter(
            $counts[$first->key()] ?? [],
            $first->isReachedBy(...),
        ));

        return array_values(array_filter(
            array_map(strval(...), $candidates),
            static fn (string $userId): bool => $quest->isAccomplishedWith(array_map(
                static fn (array $byMember): int => $byMember[$userId] ?? 0,
                $counts,
            )),
        ));
    }
}
