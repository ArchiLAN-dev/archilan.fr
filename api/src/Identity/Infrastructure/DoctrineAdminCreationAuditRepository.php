<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Domain\AdminCreationAudit;
use App\Identity\Domain\AdminCreationAuditRepositoryInterface;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdminCreationAuditRepository implements AdminCreationAuditRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function saveAdminWithAudit(User $user, AdminCreationAudit $audit): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->persist($audit);
        $this->entityManager->flush();
    }
}
