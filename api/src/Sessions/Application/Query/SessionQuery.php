<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Domain\Entity\Session;
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
     * True when the user owns the slot at $slotIndex in the session, i.e. the SessionSlot whose
     * slotOrder equals $slotIndex belongs to the caller. Session-level authorization is NOT enough:
     * a registrant/participant may only read or act on their own slots (issues #252 / #253). Admin
     * bypass is the caller's responsibility. The reference pattern is the weekly-run path
     * (WeeklyRunSlotQuery::findLaunchedEntryInfo), which already rejects foreign slots.
     */
    public function doesUserOwnSlot(string $userId, string $sessionId, int $slotIndex): bool
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return false;
        }

        $ownerKey = $this->slotOwnerKey($userId, $sessionId, $session->getEventId());
        if (null === $ownerKey) {
            return false;
        }

        return array_any($this->slots->findByRegistrationAndSession($ownerKey, $sessionId), fn ($slot) => $slot->getSlotOrder() === $slotIndex);
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
