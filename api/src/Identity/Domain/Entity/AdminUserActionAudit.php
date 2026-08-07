<?php

declare(strict_types=1);

namespace App\Identity\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * An admin action applied to another member's account (story 36.6).
 *
 * The site already traced role changes, admin creations, deletions, run actions and private-event
 * access - but nothing covered "an admin revoked this account's sessions" or "an admin validated this
 * email in their place". Acting on someone's account without leaving a trace would contradict the whole
 * point of epic 36, half of which exists to make past actions readable.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_identity_admin_user_action_audits_target', columns: ['target_user_id'])]
#[ORM\Index(name: 'idx_identity_admin_user_action_audits_admin', columns: ['admin_user_id'])]
final class AdminUserActionAudit
{
    public const string ACTION_REVOKE_SESSIONS = 'revoke_sessions';
    public const string ACTION_VERIFY_EMAIL = 'verify_email';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'target_user_id', type: 'string', length: 32)]
        private string $targetUserId,
        #[ORM\Column(name: 'admin_user_id', type: 'string', length: 32)]
        private string $adminUserId,
        #[ORM\Column(type: 'string', length: 40)]
        private string $action,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function record(
        string $targetUserId,
        string $adminUserId,
        string $action,
        \DateTimeImmutable $now,
    ): self {
        return new self(bin2hex(random_bytes(16)), $targetUserId, $adminUserId, $action, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTargetUserId(): string
    {
        return $this->targetUserId;
    }

    public function getAdminUserId(): string
    {
        return $this->adminUserId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
