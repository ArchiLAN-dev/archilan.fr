<?php

declare(strict_types=1);

namespace App\Payments\Infrastructure;

use App\Payments\Domain\HelloAssoSyncLog;
use App\Payments\Domain\HelloAssoSyncLogRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineHelloAssoSyncLogRepository implements HelloAssoSyncLogRepositoryInterface
{
    private string $table;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->table = $entityManager->getClassMetadata(HelloAssoSyncLog::class)->getTableName();
    }

    public function findRecentByFormSlug(string $formSlug, int $limit = 10): array
    {
        /* @var list<HelloAssoSyncLog> */
        return $this->entityManager->getRepository(HelloAssoSyncLog::class)->findBy(
            ['formSlug' => $formSlug],
            ['attemptAt' => 'DESC'],
            $limit,
        );
    }

    public function persist(HelloAssoSyncLog $log): void
    {
        $this->entityManager->persist($log);
    }

    public function save(HelloAssoSyncLog $log): void
    {
        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        $qb = $this->connection->createQueryBuilder();

        return (int) $qb->delete($this->table)
            ->where('attempt_at < :threshold')
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->executeStatement();
    }
}
