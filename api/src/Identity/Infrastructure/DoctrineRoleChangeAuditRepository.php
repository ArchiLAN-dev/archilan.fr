<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\RoleChangeAudit;
use App\Identity\Domain\RoleChangeAuditRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRoleChangeAuditRepository implements RoleChangeAuditRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function saveAuditAndFlushUser(RoleChangeAudit $audit): void
    {
        $this->entityManager->persist($audit);
        $this->entityManager->flush();
    }
}
