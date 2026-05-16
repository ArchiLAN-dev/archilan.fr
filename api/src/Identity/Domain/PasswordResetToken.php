<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_identity_password_reset_tokens_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_identity_password_reset_tokens_user', columns: ['user_id'])]
class PasswordResetToken
{
    public const TTL_MINUTES = 15;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private readonly string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private readonly string $userId,
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64)]
        private readonly string $tokenHash,
        #[ORM\Column(name: 'expires_at', type: 'datetimetz_immutable')]
        private readonly \DateTimeImmutable $expiresAt,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private readonly \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'used_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $usedAt = null,
    ) {
    }

    public static function issue(string $userId, string $rawToken, \DateTimeImmutable $now): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            $userId,
            hash('sha256', $rawToken),
            $now->modify(sprintf('+%d minutes', self::TTL_MINUTES)),
            $now,
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function isValid(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $this->expiresAt > $now;
    }

    public function markUsed(\DateTimeImmutable $at): void
    {
        if (null === $this->usedAt) {
            $this->usedAt = $at;
        }
    }
}
