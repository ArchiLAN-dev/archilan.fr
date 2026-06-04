<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Sessions\Domain\RunAuditLog;
use App\Sessions\Domain\RunAuditLogRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRunAuditLogRepository implements RunAuditLogRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function persist(RunAuditLog $log): void
    {
        $this->entityManager->persist($log);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
