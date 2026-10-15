<?php

declare(strict_types=1);

namespace App\Events\Infrastructure\Doctrine;

use App\Events\Domain\Entity\EventPrivateAccessLog;
use App\Events\Domain\Repository\EventPrivateAccessLogRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineEventPrivateAccessLogRepository implements EventPrivateAccessLogRepositoryInterface
{
    private string $table;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->table = $entityManager->getClassMetadata(EventPrivateAccessLog::class)->getTableName();
    }

    public function save(EventPrivateAccessLog $log): void
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    public function countDeniedSince(string $eventId, string $userId, \DateTimeImmutable $since): int
    {
        $qb = $this->connection->createQueryBuilder();
        $count = $qb->select('COUNT(*)')
            ->from($this->table)
            ->where('event_id = :eventId')
            ->andWhere('user_id = :userId')
            ->andWhere('granted = :granted')
            ->andWhere('created_at > :since')
            ->setParameter('eventId', $eventId)
            ->setParameter('userId', $userId)
            ->setParameter('granted', false, Types::BOOLEAN)
            ->setParameter('since', $since, Types::DATETIMETZ_IMMUTABLE)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        $qb = $this->connection->createQueryBuilder();

        return (int) $qb->delete($this->table)
            ->where('created_at < :threshold')
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->executeStatement();
    }
}
