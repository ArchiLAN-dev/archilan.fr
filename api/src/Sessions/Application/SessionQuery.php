<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;

final readonly class SessionQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private ActiveRegistrationQueryInterface $activeRegistration,
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
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
}
