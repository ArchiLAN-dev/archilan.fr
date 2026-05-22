<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface DeletionAuditRepositoryInterface
{
    public function save(DeletionAudit $audit): void;
}
