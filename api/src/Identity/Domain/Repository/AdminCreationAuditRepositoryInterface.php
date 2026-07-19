<?php

declare(strict_types=1);

namespace App\Identity\Domain\Repository;

use App\Identity\Domain\Entity\AdminCreationAudit;
use App\Identity\Domain\Entity\User;

interface AdminCreationAuditRepositoryInterface
{
    /**
     * Persists both the new admin user and their creation audit in a single transaction.
     */
    public function saveAdminWithAudit(User $user, AdminCreationAudit $audit): void;
}
