<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Domain\ContentReport;
use App\Community\Domain\ContentReportRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineContentReportRepository implements ContentReportRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function exists(string $reporterId, string $targetType, string $targetId): bool
    {
        return null !== $this->entityManager->getRepository(ContentReport::class)
            ->findOneBy(['reporterId' => $reporterId, 'targetType' => $targetType, 'targetId' => $targetId]);
    }

    public function save(ContentReport $report): void
    {
        $this->entityManager->persist($report);
        $this->entityManager->flush();
    }
}
