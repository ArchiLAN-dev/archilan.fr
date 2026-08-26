<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Support\SlotsPlayedBy;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Shared\Application\Support\ArchipelagoConnectionUri;

final readonly class SessionQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private ActiveRegistrationQueryInterface $activeRegistration,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private RegistrationRepositoryInterface $registrations,
        private SessionSlotRepositoryInterface $slots,
        private SessionPlayersSnapshotRepositoryInterface $snapshots,
        private SlotsPlayedBy $playedSlots,
    ) {
    }

    /**
     * @return array{
     *     id: string,
     *     eventId: string,
     *     status: string,
     *     host: string|null,
     *     port: int|null,
     *     bridgePort: int|null,
     *     lastLogs: string|null,
     *     archivedSpoilerPath: string|null,
     *     archivedSavePath: string|null,
     *     generatedOutputKey: string|null,
     * }|null
     */
    public function findById(string $id): ?array
    {
        $session = $this->sessions->findById($id);
        if (!$session instanceof Session) {
            return null;
        }

        return [
            'id' => $session->getId(),
            'eventId' => $session->getEventId(),
            'status' => $session->getStatus(),
            'host' => $session->getHost(),
            'port' => $session->getPort(),
            // Adresse à donner à un client ; hôte et port bruts restent exposés pour l'admin, le
            // diagnostic, et les clients qui attendent les deux champs séparés (epic 37).
            'connectionUri' => ArchipelagoConnectionUri::tryBuild($session->getHost(), $session->getPort()),
            'bridgePort' => $session->getBridgePort(),
            'lastLogs' => $session->getLastLogs(),
            'archivedSpoilerPath' => $session->getArchivedSpoilerPath(),
            'archivedSavePath' => $session->getArchivedSavePath(),
            'generatedOutputKey' => $session->getGeneratedOutputKey(),
        ];
    }

    public function hasActiveEventRegistration(string $userId, string $eventId): bool
    {
        return $this->activeRegistration->hasActiveForEvent($userId, $eventId);
    }

    public function isUserAuthorizedForSession(string $userId, string $eventId, string $sessionId): bool
    {
        if ($this->hasActiveEventRegistration($userId, $eventId)) {
            return true;
        }

        $personalRun = $this->runs->findBySessionId($sessionId);
        if ($personalRun instanceof Run) {
            if ($personalRun->isOwnedBy($userId)) {
                return true;
            }

            $participant = $this->participants->findByRunAndUser($personalRun->getId(), $userId);

            return null !== $participant;
        }

        return false;
    }

    /**
     * True when the user owns the Archipelago slot numbered $slotIndex in the session. Session-level
     * authorization is NOT enough: a registrant/participant may only read or act on their own slots
     * (issues #252 / #253). Admin bypass is the caller's responsibility.
     *
     * $slotIndex is the *Archipelago* slot number, the key the bridge indexes its state by and the
     * one the slot pages carry in their URL. It is decided at generation time, by Archipelago, from
     * the casefolded yaml filenames - and the orchestrator injects a `_bridge_observer.yaml`
     * spectator slot that sorts before every player name, so slot 1 is always the bridge and real
     * players start at 2.
     *
     * This used to be compared against SessionSlot::getSlotOrder(), which is a different number
     * entirely: the rank of a game inside *one* participant's own list (`$idx + 1`, see
     * RunParticipant::replaceSlots / Registration::replaceSlots). Every player's first game carries
     * slot_order 1, so the check pointed at the bridge's slot and denied every non-admin - hints,
     * hint purchases, hint status and item locations were dead for players on both personal runs
     * and event sessions. It only ever passed through the admin bypass.
     *
     * The Archipelago slot number is resolved to its generated slot name the same way the rest of
     * the code joins the two worlds (RecordSlotGoal uses findBySessionAndSlotName), reading the last
     * players state the bridge pushed. Fail-closed: no snapshot yet means no proof of ownership.
     */
    public function doesUserOwnSlot(string $userId, string $sessionId, int $slotIndex): bool
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return false;
        }

        $slotName = $this->archipelagoSlotName($sessionId, $slotIndex);
        if (null === $slotName) {
            return false;
        }

        $slot = $this->slots->findBySessionAndSlotName($sessionId, $slotName);
        if (!$slot instanceof SessionSlot) {
            return false;
        }

        // Owning the slot is one way in; being a co-player of it is the other (story 16.17). The
        // owner key stays null for someone with no slots of their own, who may still co-play one.
        return $this->playedSlots->plays($slot, $this->slotOwnerKey($userId, $sessionId, $session->getEventId()), $userId);
    }

    /**
     * The generated slot name Archipelago gave to $slotIndex, read from the last players state the
     * bridge pushed (payload shape: {"slots": {"<archipelago slot>": {"slot_name": "..."}}}).
     */
    private function archipelagoSlotName(string $sessionId, int $slotIndex): ?string
    {
        $snapshot = $this->snapshots->findBySessionId($sessionId);
        if (!$snapshot instanceof SessionPlayersSnapshot) {
            return null;
        }

        $slots = $snapshot->getPayload()['slots'] ?? null;
        if (!is_array($slots)) {
            return null;
        }

        // json_decode canonicalizes the payload's numeric string keys ("2") to int offsets.
        $slot = $slots[$slotIndex] ?? null;
        if (!is_array($slot)) {
            return null;
        }

        $slotName = $slot['slot_name'] ?? null;

        return is_string($slotName) && '' !== $slotName ? $slotName : null;
    }

    /**
     * The key that scopes the caller's SessionSlot rows. For a personal-run session the
     * SessionSlot.registrationId column holds the participant userId (LaunchPersonalRunJobHandler);
     * for an event session it holds the caller's registration id. Returns null when the caller has
     * no slots in the session.
     */
    private function slotOwnerKey(string $userId, string $sessionId, string $eventId): ?string
    {
        if ($this->runs->findBySessionId($sessionId) instanceof Run) {
            return $userId;
        }

        return $this->registrations->findByEventAndUser($eventId, $userId)?->getId();
    }
}
