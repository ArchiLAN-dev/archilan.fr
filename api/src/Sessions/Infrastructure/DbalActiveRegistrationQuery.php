<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Registrations\Domain\Registration;
use App\Sessions\Application\ActiveRegistrationQueryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DbalActiveRegistrationQuery implements ActiveRegistrationQueryInterface
{
    private string $registrationTable;

    public function __construct(private Connection $connection, EntityManagerInterface $em)
    {
        $this->registrationTable = $em->getClassMetadata(Registration::class)->getTableName();
    }

    public function hasActiveForEvent(string $userId, string $eventId): bool
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
}
