<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SessionQuery
{
    use EntityFinderTrait;

    private string $registrationTable;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->registrationTable = $entityManager->getClassMetadata(Registration::class)->getTableName();
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
        $qb = $this->connection->createQueryBuilder();
        $result = $qb->select('COUNT(r.id)')
            ->from($this->registrationTable, 'r')
            ->where($qb->expr()->and(
                $qb->expr()->eq('r.event_id', ':eventId'),
                $qb->expr()->eq('r.user_id', ':userId'),
                $qb->expr()->eq('r.status', ':status'),
                $qb->expr()->isNotNull('r.submitted_at'),
            ))
            ->setParameter('eventId', $eventId)
            ->setParameter('userId', $userId)
            ->setParameter('status', Registration::STATUS_RESERVED)
            ->executeQuery()
            ->fetchOne();

        return false !== $result && is_numeric($result) && (int) $result > 0;
    }

    public function isUserAuthorizedForSession(string $userId, string $eventId, string $sessionId): bool
    {
        if ($this->hasActiveEventRegistration($userId, $eventId)) {
            return true;
        }

        $personalRun = $this->entityManager->getRepository(Run::class)->findOneBy(['sessionId' => $sessionId]);
        if ($personalRun instanceof Run) {
            if ($personalRun->isOwnedBy($userId)) {
                return true;
            }
            $participant = $this->entityManager->getRepository(RunParticipant::class)->findOneBy([
                'runId' => $personalRun->getId(),
                'userId' => $userId,
            ]);

            return null !== $participant;
        }

        return false;
    }
}
