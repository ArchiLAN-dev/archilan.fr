<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A one-way block. Blocking is the strongest user action: it retracts any friendship, hides the social
 * surface both ways, and prevents re-interaction. It overrides every audience (story 30.7, epic §C).
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_block')]
#[ORM\UniqueConstraint(name: 'uniq_community_block', columns: ['blocker_id', 'blocked_id'])]
#[ORM\Index(name: 'idx_community_block_blocked', columns: ['blocked_id'])]
final class Block
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'blocker_id', type: 'string', length: 32)]
        private string $blockerId,
        #[ORM\Column(name: 'blocked_id', type: 'string', length: 32)]
        private string $blockedId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(string $blockerId, string $blockedId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $blockerId, $blockedId, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getBlockerId(): string
    {
        return $this->blockerId;
    }

    public function getBlockedId(): string
    {
        return $this->blockedId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
