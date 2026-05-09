<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'identity_account_deletion_audits')]
class AccountDeletionAudit
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'email_hash', type: 'string', length: 64)]
        private string $emailHash,
        #[ORM\Column(type: 'string', length: 120)]
        private string $reason,
        #[ORM\Column(name: 'deleted_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $deletedAt,
    ) {
    }

    public static function record(string $userId, string $emailHash, string $reason, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $emailHash, $reason, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getEmailHash(): string
    {
        return $this->emailHash;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
