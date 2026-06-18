<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A kudos (reaction) an actor gives to a target: a run (activity entry) or someone's achievement unlock
 * (an AchievementGrant). One per (actor, targetType, targetId) - giving twice is a no-op, removing toggles
 * it off (story 30.11).
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_kudos')]
#[ORM\UniqueConstraint(name: 'uniq_community_kudos', columns: ['actor_id', 'target_type', 'target_id'])]
#[ORM\Index(name: 'idx_community_kudos_target', columns: ['target_type', 'target_id'])]
final class Kudos
{
    public const TARGET_RUN = 'run';
    public const TARGET_ACHIEVEMENT = 'achievement';

    public const TARGET_TYPES = [self::TARGET_RUN, self::TARGET_ACHIEVEMENT];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'actor_id', type: 'string', length: 32)]
        private string $actorId,
        #[ORM\Column(name: 'target_type', type: 'string', length: 16)]
        private string $targetType,
        #[ORM\Column(name: 'target_id', type: 'string', length: 32)]
        private string $targetId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function give(string $actorId, string $targetType, string $targetId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $actorId, $targetType, $targetId, $now);
    }

    public static function isValidTargetType(string $targetType): bool
    {
        return in_array($targetType, self::TARGET_TYPES, true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getActorId(): string
    {
        return $this->actorId;
    }

    public function getTargetType(): string
    {
        return $this->targetType;
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
