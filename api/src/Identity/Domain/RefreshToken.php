<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_identity_refresh_tokens_token_hash', columns: ['token_hash'])]
#[ORM\Index(name: 'idx_identity_refresh_tokens_user_revoked', columns: ['user_id', 'revoked_at'])]
#[ORM\Index(name: 'idx_identity_refresh_tokens_family', columns: ['family_id'])]
class RefreshToken
{
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
        #[ORM\Column(name: 'user_agent', type: 'string', length: 255, nullable: true)]
        private readonly ?string $userAgent,
        #[ORM\Column(name: 'revoked_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $revokedAt = null,
        #[ORM\Column(name: 'remember_me', type: 'boolean')]
        private readonly bool $rememberMe = true,
        #[ORM\Column(name: 'family_id', type: 'string', length: 32, nullable: true)]
        private readonly ?string $familyId = null,
        #[ORM\Column(name: 'replaced_by_token_hash', type: 'string', length: 64, nullable: true)]
        private ?string $replacedByTokenHash = null,
    ) {
    }

    public static function issue(
        string $userId,
        string $rawToken,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
        ?string $userAgent = null,
        bool $rememberMe = true,
        ?string $familyId = null,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            $userId,
            hash('sha256', $rawToken),
            $expiresAt,
            $now,
            null !== $userAgent ? substr($userAgent, 0, 255) : null,
            rememberMe: $rememberMe,
            familyId: $familyId ?? bin2hex(random_bytes(16)),
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

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRememberMe(): bool
    {
        return $this->rememberMe;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        if (null === $this->revokedAt) {
            $this->revokedAt = $at;
        }
    }

    public function getFamilyId(): ?string
    {
        return $this->familyId;
    }

    public function getReplacedByTokenHash(): ?string
    {
        return $this->replacedByTokenHash;
    }

    /**
     * Mark this token as rotated into a successor: it is revoked and remembers which token
     * replaced it, so a re-presentation shortly after can be told apart from a genuine reuse.
     */
    public function markRotated(string $successorTokenHash, \DateTimeImmutable $at): void
    {
        $this->replacedByTokenHash = $successorTokenHash;
        if (null === $this->revokedAt) {
            $this->revokedAt = $at;
        }
    }

    /**
     * Whether this token was rotated (has a successor) and revoked within the grace window -
     * i.e. a re-presentation is very likely a benign retry (lost refresh response / wake race)
     * rather than a stolen-token reuse.
     */
    public function wasRotatedWithinGrace(\DateTimeImmutable $now, int $graceSeconds): bool
    {
        return null !== $this->replacedByTokenHash
            && null !== $this->revokedAt
            && ($now->getTimestamp() - $this->revokedAt->getTimestamp()) <= $graceSeconds;
    }
}
