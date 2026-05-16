<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(name: 'idx_identity_role_change_audits_target_user_id', columns: ['target_user_id'])]
#[ORM\Index(name: 'idx_identity_role_change_audits_admin_user_id', columns: ['admin_user_id'])]
class RoleChangeAudit
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'target_user_id', type: 'string', length: 32)]
        private string $targetUserId,
        #[ORM\Column(name: 'admin_user_id', type: 'string', length: 32)]
        private string $adminUserId,
        #[ORM\Column(name: 'previous_role', type: 'string', length: 20)]
        private string $previousRole,
        #[ORM\Column(name: 'new_role', type: 'string', length: 20)]
        private string $newRole,
        #[ORM\Column(name: 'changed_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $changedAt,
    ) {
    }

    public static function record(
        string $targetUserId,
        string $adminUserId,
        string $previousRole,
        string $newRole,
        \DateTimeImmutable $now,
    ): self {
        return new self(bin2hex(random_bytes(16)), $targetUserId, $adminUserId, $previousRole, $newRole, $now);
    }

    public function getTargetUserId(): string
    {
        return $this->targetUserId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAdminUserId(): string
    {
        return $this->adminUserId;
    }

    public function getPreviousRole(): string
    {
        return $this->previousRole;
    }

    public function getNewRole(): string
    {
        return $this->newRole;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
