<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A member's moderation case (story 39.1): one per member, opened by their first sanction, reopened by every
 * new one and closed by a lift. It is mirrored by one post in the staff forum on Discord, created once and
 * kept for good, so a member's whole history stays in one place.
 */
#[ORM\Entity]
#[ORM\Table(name: 'moderation_case')]
#[ORM\UniqueConstraint(name: 'uniq_moderation_case_target', columns: ['target_user_id'])]
final class ModerationCase
{
    public const string STATUS_OPEN = 'open';
    public const string STATUS_CLOSED = 'closed';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'target_user_id', type: 'string', length: 32)]
        private string $targetUserId,
        #[ORM\Column(type: 'string', length: 16)]
        private string $status,
        #[ORM\Column(name: 'forum_thread_id', type: 'string', length: 32, nullable: true)]
        private ?string $forumThreadId,
        #[ORM\Column(name: 'opened_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $openedAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function open(string $targetUserId, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $targetUserId, self::STATUS_OPEN, null, $now, $now);
    }

    public function reopen(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_OPEN;
        $this->updatedAt = $now;
    }

    public function close(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_CLOSED;
        $this->updatedAt = $now;
    }

    /** One post per member: the first one is kept. */
    public function attachForumThread(string $threadId): void
    {
        if (null === $this->forumThreadId) {
            $this->forumThreadId = $threadId;
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTargetUserId(): string
    {
        return $this->targetUserId;
    }

    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getForumThreadId(): ?string
    {
        return $this->forumThreadId;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
