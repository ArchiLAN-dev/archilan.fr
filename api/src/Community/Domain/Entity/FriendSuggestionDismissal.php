<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member waved a friend suggestion away (story 43.2): that person is never suggested to them again. One way
 * only - the other side may still be suggested the member.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_friend_suggestion_dismissal')]
#[ORM\UniqueConstraint(name: 'uniq_community_friend_suggestion_dismissal', columns: ['user_id', 'dismissed_user_id'])]
final class FriendSuggestionDismissal
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'dismissed_user_id', type: 'string', length: 32)]
        private string $dismissedUserId,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(string $userId, string $dismissedUserId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $userId, $dismissedUserId, $now);
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getDismissedUserId(): string
    {
        return $this->dismissedUserId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
