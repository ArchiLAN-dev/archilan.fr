<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_identity_email_confirmation_tokens_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_identity_email_confirmation_tokens_user', columns: ['user_id'])]
class EmailConfirmationToken
{
    public const TTL_HOURS = 24;

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
        #[ORM\Column(name: 'confirmed_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $confirmedAt = null,
    ) {
    }

    public static function issue(string $userId, string $rawToken, \DateTimeImmutable $now): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            $userId,
            hash('sha256', $rawToken),
            $now->modify(sprintf('+%d hours', self::TTL_HOURS)),
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

    public function isValid(\DateTimeImmutable $now): bool
    {
        return null === $this->confirmedAt && $this->expiresAt > $now;
    }

    public function markConfirmed(\DateTimeImmutable $at): void
    {
        if (null === $this->confirmedAt) {
            $this->confirmedAt = $at;
        }
    }
}
