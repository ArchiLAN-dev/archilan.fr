<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\Entity\AdminUserActionAudit;
use App\Identity\Domain\Repository\AdminUserActionAuditRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdminUserActionAuditRepository implements AdminUserActionAuditRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(AdminUserActionAudit $audit): void
    {
        $this->entityManager->persist($audit);
        $this->entityManager->flush();
    }
}
