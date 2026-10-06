<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\Notification;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Application\Service\QuestWeekPlanner;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;

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
    ) {
    }

    /** @return int the number of quests (and chests) paid */
    public function award(): int
    {
        $current = QuestWeek::containing($this->clock->now());

        return $this->awardWeek($current->previous()) + $this->awardWeek($current);
    }

    public function awardWeek(QuestWeek $week): int
    {
        $served = $this->planner->served($week);
        if ([] === $served) {
            return 0;
        }
        $counts = $this->quests->counts($week, QuestDefinition::metricsOf($served));

        $paid = 0;
        $doneAll = null;
        foreach ($served as $quest) {
            $accomplishers = $this->accomplishers($quest, $counts);
            // Story 41.16: the chest goes to those who accomplished every quest the week serves.
            $doneAll = null === $doneAll ? $accomplishers : array_values(array_intersect($doneAll, $accomplishers));
            foreach ($accomplishers as $userId) {
                $paid += $this->pay(
                    $userId, $quest->getReward(), sprintf('Quête : %s', $quest->getTitle()),
                    sprintf('quest:%s:%s:%s', $week->key, $quest->getId(), $userId), sprintf('quête « %s »', $quest->getTitle()),
                );
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
     * @param array<string, array<string, int>> $counts metric => member => count
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
            $counts[$first->metric->value] ?? [],
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
