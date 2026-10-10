<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member starred one of their friends (story 43.11a): the friend goes to the top of their lists. One way only, and
 * never shown to the friend. Not a flag on the friendship: that row is shared by both sides.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_friend_favorite')]
#[ORM\UniqueConstraint(name: 'uniq_community_friend_favorite', columns: ['user_id', 'favorite_user_id'])]
final class FriendFavorite
{
    public const int MAX_PER_USER = 15;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'favorite_user_id', type: 'string', length: 32)]
        private string $favoriteUserId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(string $userId, string $favoriteUserId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $favoriteUserId, $now);
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getFavoriteUserId(): string
    {
        return $this->favoriteUserId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
