<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member completed a collection of achievements (story 30.52). Written once, when its reward is given; never
 * removed, so an achievement added to the collection later takes nothing back and gives nothing twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_achievement_collection_completion')]
#[ORM\UniqueConstraint(name: 'uniq_community_collection_completion', columns: ['user_id', 'collection_id'])]
final class AchievementCollectionCompletion
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'collection_id', type: 'string', length: 32)]
        private string $collectionId,
        #[ORM\Column(name: 'completed_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $completedAt,
    ) {
    }

    public static function complete(string $userId, string $collectionId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $collectionId, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getCollectionId(): string
    {
        return $this->collectionId;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }
}
