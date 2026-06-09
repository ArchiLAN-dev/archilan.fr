<?php

declare(strict_types=1);

namespace App\Events\Infrastructure;

use App\Events\Domain\EventPrivateAccessLog;
use App\Events\Domain\EventPrivateAccessLogRepositoryInterface;
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

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        $qb = $this->connection->createQueryBuilder();

        return (int) $qb->delete($this->table)
            ->where('created_at < :threshold')
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->executeStatement();
    }
}
