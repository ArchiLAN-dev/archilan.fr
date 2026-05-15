<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\PersonalRuns\Domain\PersonalRun;
use App\PersonalRuns\Domain\PersonalRunParticipant;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SessionQuery
{
    use EntityFinderTrait;

    public function __construct(private EntityManagerInterface $entityManager)
    {
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
     * }|null
     */
    public function findById(string $id): ?array
    {
        try {
            $session = $this->findOrFail(Session::class, $id);
        } catch (\RuntimeException) {
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
        ];
    }

    public function hasActiveEventRegistration(string $userId, string $eventId): bool
    {
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Registration::class, 'r')
            ->where('r.eventId = :eventId AND r.userId = :userId AND r.status = :status AND r.submittedAt IS NOT NULL')
            ->setParameter('eventId', $eventId)
            ->setParameter('userId', $userId)
            ->setParameter('status', Registration::STATUS_RESERVED)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function isUserAuthorizedForSession(string $userId, string $eventId, string $sessionId): bool
    {
        if ($this->hasActiveEventRegistration($userId, $eventId)) {
            return true;
        }

        $personalRun = $this->entityManager->getRepository(PersonalRun::class)->findOneBy(['sessionId' => $sessionId]);
        if ($personalRun instanceof PersonalRun) {
            if ($personalRun->isOwnedBy($userId)) {
                return true;
            }
            $participant = $this->entityManager->getRepository(PersonalRunParticipant::class)->findOneBy([
                'personalRunId' => $personalRun->getId(),
                'userId' => $userId,
            ]);

            return null !== $participant;
        }

        return false;
    }
}
