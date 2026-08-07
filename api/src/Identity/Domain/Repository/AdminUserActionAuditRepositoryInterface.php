<?php

declare(strict_types=1);

namespace App\Identity\Domain\Repository;

use App\Identity\Domain\Entity\AdminUserActionAudit;

interface AdminUserActionAuditRepositoryInterface
{
    public function save(AdminUserActionAudit $audit): void;
}
