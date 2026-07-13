<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\RunAuditLog;

interface RunAuditLogRepositoryInterface
{
    public function persist(RunAuditLog $log): void;

    public function flush(): void;
}
