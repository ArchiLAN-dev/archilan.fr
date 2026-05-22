<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineWeeklyRunRepository implements WeeklyRunRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    public function findById(string $id): ?WeeklyRun
    {
        return $this->entityManager->find(WeeklyRun::class, $id);
    }

    public function findAllActive(): array
    {
        /* @var list<WeeklyRun> */
        return $this->entityManager->getRepository(WeeklyRun::class)->findBy([
            'status' => WeeklyRun::STATUS_ACTIVE,
        ]);
    }

    public function existsByTemplateAndWeek(string $templateId, int $weekYear, int $weekNumber): bool
    {
        $countRaw = $this->connection->createQueryBuilder()
            ->select('COUNT(wr.id)')
            ->from('weekly_runs', 'wr')
            ->where('wr.template_id = :templateId')
            ->andWhere('wr.week_year = :year')
            ->andWhere('wr.week_number = :week')
            ->setParameter('templateId', $templateId)
            ->setParameter('year', $weekYear)
            ->setParameter('week', $weekNumber)
            ->executeQuery()
            ->fetchOne();

        return false !== $countRaw && is_numeric($countRaw) && 0 < (int) $countRaw;
    }

    public function save(WeeklyRun $run): void
    {
        $this->entityManager->persist($run);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
