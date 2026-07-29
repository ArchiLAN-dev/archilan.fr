<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single persisted game feed event (story 32.6): one item send/find, with the item, the origin
 * check, the sender and receiver worlds, and when it happened.
 *
 * Append-only history. The bridge already builds this exact shape for every AP `ItemSend` (including a
 * solo player's self-finds) and broadcasts it live; this row keeps it so a timeline and per-player
 * check curves can be replayed after the run, not only watched live. Indexed on
 * `(session_id, occurred_at)` for cheap ordering and minute-bucketing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'session_feed_event')]
#[ORM\Index(name: 'idx_feed_event_session_time', columns: ['session_id', 'occurred_at'])]
final class SessionFeedEvent
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $id,

        #[ORM\Column(name: 'session_id', type: Types::STRING, length: 64)]
        private string $sessionId,

        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $type,

        #[ORM\Column(type: Types::TEXT)]
        private string $text,

        #[ORM\Column(name: 'occurred_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $occurredAt,

        #[ORM\Column(name: 'item_id', type: Types::INTEGER, nullable: true)]
        private ?int $itemId,

        #[ORM\Column(name: 'item_name', type: Types::STRING, length: 255, nullable: true)]
        private ?string $itemName,

        #[ORM\Column(name: 'item_flags', type: Types::INTEGER, nullable: true)]
        private ?int $itemFlags,

        #[ORM\Column(name: 'location_id', type: Types::INTEGER, nullable: true)]
        private ?int $locationId,

        #[ORM\Column(name: 'location_name', type: Types::STRING, length: 255, nullable: true)]
        private ?string $locationName,

        #[ORM\Column(name: 'sender_slot', type: Types::INTEGER, nullable: true)]
        private ?int $senderSlot,

        #[ORM\Column(name: 'sender_name', type: Types::STRING, length: 255, nullable: true)]
        private ?string $senderName,

        #[ORM\Column(name: 'sender_game', type: Types::STRING, length: 255, nullable: true)]
        private ?string $senderGame,

        #[ORM\Column(name: 'receiver_slot', type: Types::INTEGER, nullable: true)]
        private ?int $receiverSlot,

        #[ORM\Column(name: 'receiver_name', type: Types::STRING, length: 255, nullable: true)]
        private ?string $receiverName,

        #[ORM\Column(name: 'receiver_game', type: Types::STRING, length: 255, nullable: true)]
        private ?string $receiverGame,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getItemId(): ?int
    {
        return $this->itemId;
    }

    public function getItemName(): ?string
    {
        return $this->itemName;
    }

    /** AP item classification bits (1 = progression); null when the bridge sent none. */
    public function getItemFlags(): ?int
    {
        return $this->itemFlags;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function getLocationName(): ?string
    {
        return $this->locationName;
    }

    public function getSenderSlot(): ?int
    {
        return $this->senderSlot;
    }

    public function getSenderName(): ?string
    {
        return $this->senderName;
    }

    public function getSenderGame(): ?string
    {
        return $this->senderGame;
    }

    public function getReceiverSlot(): ?int
    {
        return $this->receiverSlot;
    }

    public function getReceiverName(): ?string
    {
        return $this->receiverName;
    }

    public function getReceiverGame(): ?string
    {
        return $this->receiverGame;
    }
}
