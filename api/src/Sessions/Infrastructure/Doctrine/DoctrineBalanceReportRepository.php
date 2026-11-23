<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Doctrine;

use App\Sessions\Domain\Entity\BalanceReport;
use App\Sessions\Domain\Repository\BalanceReportRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineBalanceReportRepository implements BalanceReportRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(string $scopeType, string $scopeId): ?BalanceReport
    {
        return $this->entityManager->find(BalanceReport::class, BalanceReport::idFor($scopeType, $scopeId));
    }

    public function save(BalanceReport $report): void
    {
        $this->entityManager->persist($report);
        $this->entityManager->flush();
    }
}
