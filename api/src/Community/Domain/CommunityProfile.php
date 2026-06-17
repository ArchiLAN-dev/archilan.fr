<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member's community profile: a 1-1 companion to an Identity `User`, created lazily on first view.
 * Epic 30 foundation (story 30.1) - holds only identity linkage for now; customization (bio, audience,
 * avatar cache, showcases…) is layered on by later stories. Presentation prefs only; the canonical
 * identity (name/slug) stays in `Identity\User`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_profile')]
#[ORM\UniqueConstraint(name: 'uniq_community_profile_user', columns: ['user_id'])]
final class CommunityProfile
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        /** Cached, resolved avatar URL (Discord/Steam) - snapshot, refreshed lazily (story 30.2). */
        #[ORM\Column(name: 'avatar_url', type: 'string', length: 512, nullable: true)]
        private ?string $avatarUrl = null,
        #[ORM\Column(name: 'avatar_resolved_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $avatarResolvedAt = null,
    ) {
    }

    public static function create(string $userId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $now, $now);
    }

    /**
     * Store the resolved avatar URL (or null when none/unresolvable). Recording the timestamp even for
     * a null result prevents re-hammering the external API before the next staleness window.
     */
    public function cacheAvatar(?string $avatarUrl, \DateTimeImmutable $now): void
    {
        $this->avatarUrl = $avatarUrl;
        $this->avatarResolvedAt = $now;
        $this->updatedAt = $now;
    }

    public function isAvatarStale(\DateTimeImmutable $now, int $ttlSeconds): bool
    {
        if (null === $this->avatarResolvedAt) {
            return true;
        }

        return ($now->getTimestamp() - $this->avatarResolvedAt->getTimestamp()) >= $ttlSeconds;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function getAvatarResolvedAt(): ?\DateTimeImmutable
    {
        return $this->avatarResolvedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
