<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineWeeklyEntryRepository implements WeeklyEntryRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    public function findById(string $id): ?WeeklyEntry
    {
        return $this->entityManager->find(WeeklyEntry::class, $id);
    }

    public function findByExternalSessionId(string $externalSessionId): ?WeeklyEntry
    {
        /* @var WeeklyEntry|null */
        return $this->entityManager->getRepository(WeeklyEntry::class)->findOneBy([
            'externalSessionId' => $externalSessionId,
        ]);
    }

    public function countByRunAndUser(string $weeklyRunId, string $userId): int
    {
        $countRaw = $this->connection->createQueryBuilder()
            ->select('COUNT(we.id)')
            ->from('weekly_entries', 'we')
            ->where('we.weekly_run_id = :runId')
            ->andWhere('we.user_id = :userId')
            ->setParameter('runId', $weeklyRunId)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();

        return (false !== $countRaw && is_numeric($countRaw)) ? (int) $countRaw : 0;
    }

    public function findActiveEntriesForRun(string $weeklyRunId): array
    {
        /** @var list<WeeklyEntry> $all */
        $all = $this->entityManager->getRepository(WeeklyEntry::class)->findBy([
            'weeklyRunId' => $weeklyRunId,
        ]);

        return array_values(array_filter(
            $all,
            static fn (WeeklyEntry $e): bool => null !== $e->getExternalSessionId() && null === $e->getGoalReachedAt(),
        ));
    }

    public function save(WeeklyEntry $entry): void
    {
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    public function remove(WeeklyEntry $entry): void
    {
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
