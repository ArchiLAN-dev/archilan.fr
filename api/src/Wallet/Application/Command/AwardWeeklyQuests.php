<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\Notification;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Query\WeeklyQuestsQueryInterface;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\WeeklyQuest;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;

/**
 * Pays the quests of the week (story 41.6). Each quest pays a member once a week (its ledger key names the week,
 * the quest and the member), so the hourly run can pass again and again. The week just ended is paid too, for a
 * quest done in its last hour. A banned or erased member earns nothing.
 */
final readonly class AwardWeeklyQuests
{
    public function __construct(
        private WeeklyQuestsQueryInterface $quests,
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
        $paid = 0;
        foreach ($this->quests->completers($week) as $questKey => $userIds) {
            $quest = WeeklyQuest::from($questKey);
            foreach ($userIds as $userId) {
                try {
                    $recorded = $this->record->record(new RecordPelleMovementInput(
                        $userId, $quest->reward(), PelleKind::Gold, null, PelleReason::QuestReward,
                        sprintf('Quête : %s', $quest->label()), null,
                        sprintf('quest:%s:%s:%s', $week->key, $quest->value, $userId),
                    ));
                } catch (ForbiddenException|NotFoundException) {
                    continue;
                }
                if ($recorded->alreadyRecorded) {
                    continue;
                }
                ++$paid;
                $this->notifier->notify($userId, Notification::TYPE_PELLES_ADJUSTED, [
                    'amount' => $quest->reward(),
                    'kind' => PelleKind::Gold->value,
                    'reason' => sprintf('quête « %s »', $quest->label()),
                ]);
            }
        }

        return $paid;
    }
}
