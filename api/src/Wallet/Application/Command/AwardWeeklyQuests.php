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
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;

/**
 * Pays the quests of the week (story 41.6). Each quest pays a member once a week (its ledger key names the week,
 * the quest and the member), so the hourly run can pass again and again. The week just ended is paid too, for a
 * quest done in its last hour. A banned or erased member earns nothing. Story 41.15: the quests are those the week
 * serves, accomplished when every objective is reached.
 */
final readonly class AwardWeeklyQuests
{
    public function __construct(
        private WeeklyQuestsQueryInterface $quests,
        private QuestWeekPlanner $planner,
        private RecordPelleMovement $record,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    /** @return int the number of quests paid */
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
        foreach ($served as $quest) {
            foreach ($this->accomplishers($quest, $counts) as $userId) {
                try {
                    $recorded = $this->record->record(new RecordPelleMovementInput(
                        $userId, $quest->getReward(), PelleKind::Gold, null, PelleReason::QuestReward,
                        sprintf('Quête : %s', $quest->getTitle()), null,
                        sprintf('quest:%s:%s:%s', $week->key, $quest->getId(), $userId),
                    ));
                } catch (ForbiddenException|NotFoundException) {
                    continue;
                }
                if ($recorded->alreadyRecorded) {
                    continue;
                }
                ++$paid;
                $this->notifier->notify($userId, Notification::TYPE_PELLES_ADJUSTED, [
                    'amount' => $quest->getReward(),
                    'kind' => PelleKind::Gold->value,
                    'reason' => sprintf('quête « %s »', $quest->getTitle()),
                ]);
            }
        }

        return $paid;
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
