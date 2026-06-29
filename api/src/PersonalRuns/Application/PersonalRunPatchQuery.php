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
     * Resolve the output archive key plus the caller's own slot names (`slotNames`) and every slot in
     * the session (`allSlotNames`). The latter is needed to attribute a patch file to the single
     * longest-matching slot name: custom names can be `_`-boundary prefixes of one another, so a plain
     * prefix test is not enough (see PersonalRunPatchController::belongsToOwnSlot). Returns null when the
     * run isn't generated or the user is not a player in it.
     *
     * @return array{outputKey: string, slotNames: list<string>, allSlotNames: list<string>}|null
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
        // personal runs, and the resolved slot name (SlotNameGenerator / the player's custom
        // YAML name) used by the AP server to name the patch files.
        $slotNames = [];
        foreach ($this->slots->findByRegistrationAndSession($userId, $sessionId) as $slot) {
            $slotNames[] = $slot->getSlotName();
        }
        if ([] === $slotNames) {
            return null;
        }

        $allSlotNames = [];
        foreach ($this->slots->findBySessionId($sessionId) as $slot) {
            $allSlotNames[] = $slot->getSlotName();
        }

        $session = $this->sessions->findById($sessionId);
        $outputKey = ($session instanceof Session ? $session->getGeneratedOutputKey() : null)
            ?? $sessionId.'/output/archive.zip';

        return ['outputKey' => $outputKey, 'slotNames' => $slotNames, 'allSlotNames' => $allSlotNames];
    }
}
