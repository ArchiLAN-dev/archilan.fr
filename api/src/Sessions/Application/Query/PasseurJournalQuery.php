<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;

/**
 * Story 52.5: the journal of the Passeur - every call, opened or failed, with who paid, whose world, what for how
 * much. An event's and a session's for the staff, a private run's for its owner too.
 */
final readonly class PasseurJournalQuery
{
    public function __construct(
        private PasseurOpeningQueryInterface $openings,
        private SessionRepositoryInterface $sessions,
        private EventRepositoryInterface $events,
        private RunRepositoryInterface $runs,
    ) {
    }

    /**
     * @return list<PasseurOpeningRow>
     *
     * @throws NotFoundException when the session does not exist
     */
    public function forSession(string $sessionId): array
    {
        if (!$this->sessions->findById($sessionId) instanceof Session) {
            throw new NotFoundException('Session introuvable.');
        }

        return $this->openings->forSession($sessionId);
    }

    /**
     * @return list<PasseurOpeningRow>
     *
     * @throws NotFoundException when the event does not exist
     */
    public function forEvent(string $eventId): array
    {
        if (!$this->events->findById($eventId) instanceof Event) {
            throw new NotFoundException('Événement introuvable.');
        }

        return $this->openings->forEvent($eventId);
    }

    /**
     * @return list<PasseurOpeningRow>
     *
     * @throws NotFoundException  when the run does not exist
     * @throws ForbiddenException when the viewer is neither its owner nor an admin
     */
    public function forRun(string $runId, string $viewerId, bool $isAdmin): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            throw new NotFoundException('Partie introuvable.');
        }
        if (!$isAdmin && $run->getOwnerId() !== $viewerId) {
            throw new ForbiddenException('Seul le propriétaire de la partie voit le journal du Passeur.');
        }
        $sessionId = $run->getSessionId();

        return null === $sessionId ? [] : $this->openings->forSession($sessionId);
    }
}
