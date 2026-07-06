<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Entity\DeletionAudit;
use App\Identity\Domain\Repository\DeletionAuditRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineDeletionAuditRepository implements DeletionAuditRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(DeletionAudit $audit): void
    {
        $this->entityManager->persist($audit);
        $this->entityManager->flush();
    }
}
