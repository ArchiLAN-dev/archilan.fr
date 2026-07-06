<?php

declare(strict_types=1);

namespace App\Identity\Domain\Repository;

use App\Identity\Domain\Entity\DeletionAudit;

interface DeletionAuditRepositoryInterface
{
    public function save(DeletionAudit $audit): void;
}
