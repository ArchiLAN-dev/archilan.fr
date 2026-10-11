<?php

declare(strict_types=1);

namespace App\Community\Domain\Entity;

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
    public const string TYPE_FRIEND_REQUEST_RECEIVED = 'friend_request_received';
    public const string TYPE_FRIEND_REQUEST_ACCEPTED = 'friend_request_accepted';
    public const string TYPE_COMMENT_RECEIVED = 'comment_received';
    public const string TYPE_KUDOS_RECEIVED = 'kudos_received';
    public const string TYPE_ACHIEVEMENT_UNLOCKED = 'achievement_unlocked';
    /** Admin-only: an account crossed the moderation escalation threshold (story 30.28). */
    public const string TYPE_ACCOUNT_FLAGGED = 'account_flagged';
    /** Member-facing: a moderator warned the member to fix sensitive info (story 30.29). */
    public const string TYPE_MODERATION_WARNING = 'moderation_warning';
    /** Member-facing: the staff answered the member's moderation case (story 39.3). */
    public const string TYPE_MODERATION_REPLY = 'moderation_reply';
    /** Member-facing: an admin credited or debited the member's pelles (story 41.1). */
    public const string TYPE_PELLES_ADJUSTED = 'pelles_adjusted';
    /** Member-facing: the quests of the new week are out (story 41.17). */
    public const string TYPE_QUESTS_RENEWED = 'quests_renewed';
    // Story 41.28: a cosmetic won through an achievement or a quest.
    public const string TYPE_COSMETIC_UNLOCKED = 'cosmetic_unlocked';
    // Story 30.52: a collection of achievements completed, with what it gave.
    public const string TYPE_COLLECTION_COMPLETED = 'collection_completed';
    // Story 43.11b: a starred friend registered to an event, launched a session or reached a goal.
    public const string TYPE_FRIEND_ACTIVITY = 'friend_activity';

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
