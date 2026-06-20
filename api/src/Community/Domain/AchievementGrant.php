<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A persisted achievement unlock for a user. Created by the recompute engine; never revoked (monotonic).
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_achievement_grant')]
#[ORM\UniqueConstraint(name: 'uniq_community_achievement_grant', columns: ['user_id', 'achievement_key'])]
#[ORM\Index(name: 'idx_community_achievement_grant_user', columns: ['user_id'])]
final class AchievementGrant
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'achievement_key', type: 'string', length: 64)]
        private string $achievementKey,
        #[ORM\Column(name: 'unlocked_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $unlockedAt,
    ) {
    }

    public static function grant(string $userId, string $achievementKey, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $achievementKey, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getAchievementKey(): string
    {
        return $this->achievementKey;
    }

    public function getUnlockedAt(): \DateTimeImmutable
    {
        return $this->unlockedAt;
    }
}
