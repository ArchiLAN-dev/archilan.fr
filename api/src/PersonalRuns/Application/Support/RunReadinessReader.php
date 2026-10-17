<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Support;

use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Service\RunReadiness;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;

/**
 * {@see RunReadiness} fed with the co-players of the run's slots (story 16.23).
 */
final readonly class RunReadinessReader
{
    public function __construct(
        private SlotCoPlayerRepositoryInterface $coPlayers,
    ) {
    }

    /**
     * @param list<RunParticipant> $participants every participant of one run
     *
     * @return array<string, true> the user ids that can say they are ready
     */
    public function eligible(array $participants): array
    {
        $slotIds = [];
        foreach ($participants as $participant) {
            foreach ($participant->getGameSlots() as $slot) {
                $slotIds[] = $slot['slotId'];
            }
        }
        $coPlayerUserIds = [] === $slotIds
            ? []
            : array_map(static fn (SlotCoPlayer $coPlayer): string => $coPlayer->getUserId(), $this->coPlayers->findBySlotIds($slotIds));

        return RunReadiness::eligible($participants, $coPlayerUserIds);
    }
}
