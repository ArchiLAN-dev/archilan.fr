<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface RoleChangeAuditRepositoryInterface
{
    /**
     * Persists the audit record and flushes both it and the already-managed user.
     */
    public function saveAuditAndFlushUser(RoleChangeAudit $audit): void;
}
