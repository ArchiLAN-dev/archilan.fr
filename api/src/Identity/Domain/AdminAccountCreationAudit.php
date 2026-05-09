<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'identity_admin_account_creation_audits')]
#[ORM\Index(name: 'idx_identity_admin_account_creation_audits_created_user_id', columns: ['created_user_id'])]
#[ORM\Index(name: 'idx_identity_admin_account_creation_audits_creator_user_id', columns: ['creator_user_id'])]
class AdminAccountCreationAudit
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'created_user_id', type: 'string', length: 32)]
        private string $createdUserId,
        #[ORM\Column(name: 'creator_user_id', type: 'string', length: 32)]
        private string $creatorUserId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function record(string $createdUserId, string $creatorUserId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $createdUserId, $creatorUserId, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedUserId(): string
    {
        return $this->createdUserId;
    }

    public function getCreatorUserId(): string
    {
        return $this->creatorUserId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
