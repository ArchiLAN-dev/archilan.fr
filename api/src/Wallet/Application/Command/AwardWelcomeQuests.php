<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\Notification;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Query\WelcomeQuestsQueryInterface;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\WelcomeStep;
use App\Wallet\Domain\Repository\QuestRepositoryInterface;

/**
 * Pays the first steps of the newcomers (story 41.25), in the run that pays the weekly quests. Each step pays a
 * member once for life (its ledger key names the step and the member), and only an account created since the
 * welcome quests came out. A banned or erased member earns nothing.
 */
final readonly class AwardWelcomeQuests
{
    private const int CHUNK = 500;

    public function __construct(
        private WelcomeQuestsQueryInterface $welcome,
        private QuestRepositoryInterface $settings,
        private RecordPelleMovement $record,
        private Notifier $notifier,
    ) {
    }

    /** @return int the steps paid */
    public function award(): int
    {
        // Story 41.25 review: the members who may still earn first, so the steps never scan the whole history -
        // and nothing at all while there are none.
        $candidates = $this->welcome->candidates($this->settings->welcomeQuestsSince());
        $paid = 0;
        foreach (array_chunk($candidates, self::CHUNK) as $chunk) {
            foreach (WelcomeStep::cases() as $step) {
                foreach ($this->welcome->unpaid($step, $chunk) as $member) {
                    $paid += $this->pay($member['userId'], $step, $member['discordId']);
                }
            }
        }

        return $paid;
    }

    /** @return int 1 when paid now, 0 when already paid or the member cannot earn */
    private function pay(string $userId, WelcomeStep $step, ?string $discordId): int
    {
        try {
            $recorded = $this->record->record(new RecordPelleMovementInput(
                $userId, $step->reward(), PelleKind::Gold, null, PelleReason::WelcomeReward,
                sprintf('Premiers pas : %s', $step->label()), null, $step->rewardKey($userId, $discordId),
            ));
        } catch (ForbiddenException|NotFoundException) {
            return 0;
        }
        if ($recorded->alreadyRecorded) {
            return 0;
        }
        $this->notifier->notify($userId, Notification::TYPE_PELLES_ADJUSTED, [
            'amount' => $step->reward(), 'kind' => PelleKind::Gold->value, 'reason' => sprintf('premier pas « %s »', $step->label()),
        ]);

        return 1;
    }
}
