<?php

declare(strict_types=1);

namespace App\Registrations\Infrastructure;

use App\Registrations\Application\PrivateAccessGrantedQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalPrivateAccessGrantedQuery implements PrivateAccessGrantedQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function findGrantedUserIds(string $eventId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rawUserIds = $qb->select('DISTINCT l.user_id')
            ->from('event_private_access_log', 'l')
            ->where($qb->expr()->and(
                $qb->expr()->eq('l.event_id', ':eventId'),
                $qb->expr()->eq('l.granted', ':granted'),
            ))
            ->setParameter('eventId', $eventId)
            ->setParameter('granted', true)
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_filter($rawUserIds, 'is_string'));
    }

    public function countGrantedForUser(string $eventId, string $userId): int
    {
        $qb = $this->connection->createQueryBuilder();
        $raw = $qb->select('COUNT(l.id)')
            ->from('event_private_access_log', 'l')
            ->where($qb->expr()->and(
                $qb->expr()->eq('l.event_id', ':eventId'),
                $qb->expr()->eq('l.user_id', ':userId'),
                $qb->expr()->eq('l.granted', ':granted'),
            ))
            ->setParameter('eventId', $eventId)
            ->setParameter('userId', $userId)
            ->setParameter('granted', true)
            ->executeQuery()
            ->fetchOne();

        return (false !== $raw && is_numeric($raw)) ? (int) $raw : 0;
    }
}
