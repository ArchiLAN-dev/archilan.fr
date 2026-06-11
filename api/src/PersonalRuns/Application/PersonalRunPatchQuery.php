<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlotRepositoryInterface;

/**
 * Resolves, for a private-run participant, the session's generated output archive key and
 * the participant's own resolved slot name(s). The caller filters the archive to those slots
 * so a participant only ever downloads their own generated patch(es) - never the shared
 * multidata, the spoiler, or other players' patches.
 *
 * Served from the durable output archive on MinIO (not the live bridge), so it works whatever
 * the run's runtime state.
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
     * @return array{outputKey: string, slotNames: list<string>}|null null when the run isn't
     *                                                                generated or the user is not a player in it
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

        $session = $this->sessions->findById($sessionId);
        $outputKey = ($session instanceof Session ? $session->getGeneratedOutputKey() : null)
            ?? $sessionId.'/output/archive.zip';

        return ['outputKey' => $outputKey, 'slotNames' => $slotNames];
    }
}
