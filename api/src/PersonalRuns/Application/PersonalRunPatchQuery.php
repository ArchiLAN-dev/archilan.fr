<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlotRepositoryInterface;

/**
 * Resolves the bridge context for a private-run participant: the session's bridge
 * port and the participant's own resolved slot name(s). Used to let a participant
 * download only their own generated patch(es) — never the shared multidata, spoiler,
 * or other players' patches.
 */
final readonly class PersonalRunPatchQuery
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
    ) {
    }

    /**
     * @return array{bridgePort: int, slotNames: list<string>}|null null when the run
     *                                                              isn't launched, has no bridge, or the user is not a player in it
     */
    public function forParticipant(string $runId, string $userId): ?array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return null;
        }

        $sessionId = $run->getSessionId();
        if (null === $sessionId) {
            return null;
        }

        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return null;
        }

        $bridgePort = $session->getBridgePort();
        if (null === $bridgePort) {
            return null;
        }

        // SessionSlot stores the participant's user id in its registration_id column for
        // personal runs, and the resolved slot name (SlotNameGenerator) used by the AP
        // server to name the patch files.
        $slotNames = [];
        foreach ($this->slots->findByRegistrationAndSession($userId, $sessionId) as $slot) {
            $slotNames[] = $slot->getSlotName();
        }

        if ([] === $slotNames) {
            return null;
        }

        return ['bridgePort' => $bridgePort, 'slotNames' => $slotNames];
    }
}
