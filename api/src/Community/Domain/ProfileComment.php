<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A guestbook comment left on a member's profile (story 30.10). Soft-hidden (not hard-deleted) when an
 * owner/admin removes it, so moderation keeps a trace; the author may hard-delete their own.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_profile_comment')]
#[ORM\Index(name: 'idx_community_comment_profile', columns: ['profile_user_id', 'created_at'])]
#[ORM\Index(name: 'idx_community_comment_author', columns: ['author_id', 'created_at'])]
final class ProfileComment
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'profile_user_id', type: 'string', length: 32)]
        private string $profileUserId,
        #[ORM\Column(name: 'author_id', type: 'string', length: 32)]
        private string $authorId,
        #[ORM\Column(type: 'text')]
        private string $body,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'hidden_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $hiddenAt = null,
    ) {
    }

    public static function create(string $profileUserId, string $authorId, string $body, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $profileUserId, $authorId, $body, $now);
    }

    public function hide(\DateTimeImmutable $now): void
    {
        if (null === $this->hiddenAt) {
            $this->hiddenAt = $now;
        }
    }

    public function isHidden(): bool
    {
        return null !== $this->hiddenAt;
    }

    public function isAuthor(string $userId): bool
    {
        return $this->authorId === $userId;
    }

    public function isOnProfileOf(string $userId): bool
    {
        return $this->profileUserId === $userId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getProfileUserId(): string
    {
        return $this->profileUserId;
    }

    public function getAuthorId(): string
    {
        return $this->authorId;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getHiddenAt(): ?\DateTimeImmutable
    {
        return $this->hiddenAt;
    }
}
