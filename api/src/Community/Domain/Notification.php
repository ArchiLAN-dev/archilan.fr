<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * An in-app notification for a recipient (story 30.12): friendship/comment/kudos/achievement events. The
 * payload is a small denormalized bag (actor id, target ref) resolved to display data at read time.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_notification')]
#[ORM\Index(name: 'idx_community_notification_recipient', columns: ['recipient_id', 'read_at'])]
final class Notification
{
    public const TYPE_FRIEND_REQUEST_RECEIVED = 'friend_request_received';
    public const TYPE_FRIEND_REQUEST_ACCEPTED = 'friend_request_accepted';
    public const TYPE_COMMENT_RECEIVED = 'comment_received';
    public const TYPE_KUDOS_RECEIVED = 'kudos_received';
    public const TYPE_ACHIEVEMENT_UNLOCKED = 'achievement_unlocked';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'recipient_id', type: 'string', length: 32)]
        private string $recipientId,
        #[ORM\Column(type: 'string', length: 32)]
        private string $type,
        #[ORM\Column(type: 'json')]
        private array $payload,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'read_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $readAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function create(string $recipientId, string $type, array $payload, \DateTimeImmutable $now): self
    {
        return new self(bin2hex(random_bytes(16)), $recipientId, $type, $payload, $now);
    }

    public function markRead(\DateTimeImmutable $now): void
    {
        $this->readAt ??= $now;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRecipientId(): string
    {
        return $this->recipientId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }
}
