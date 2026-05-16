<?php

declare(strict_types=1);

namespace App\Registrations\Application;

use App\Registrations\Domain\Registration;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RegistrationCounter
{
    private string $table;

    public function __construct(private Connection $connection, EntityManagerInterface $em)
    {
        $this->table = $em->getClassMetadata(Registration::class)->getTableName();
    }

    public function countConfirmed(string $eventId): int
    {
        $qb = $this->connection->createQueryBuilder();

        $raw = $qb
            ->select('COUNT(r.id)')
            ->from($this->table, 'r')
            ->where($qb->expr()->eq('r.event_id', ':eventId'))
            ->andWhere($qb->expr()->neq('r.status', ':cancelled'))
            ->setParameter('eventId', $eventId)
            ->setParameter('cancelled', Registration::STATUS_CANCELLED)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($raw) ? (int) $raw : 0;
    }
}
