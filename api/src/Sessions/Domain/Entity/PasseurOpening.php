<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Story 52.4: one call to the Passeur - a blocked player paid for a chest of an absent player's world.
 *
 * Pending while the bridge opens it, then opened (the absent player got their share) or failed (the buyer got
 * their pelles back). The partial unique index is the lock: one opening at a time per chest, a failed one frees it.
 * `requestKey` makes a replayed request land on the same row. Counted for the daily limit per absent world.
 */
#[ORM\Entity]
#[ORM\Table(name: 'passeur_opening')]
#[ORM\UniqueConstraint(name: 'uniq_passeur_opening_request', columns: ['request_key'])]
#[ORM\UniqueConstraint(name: 'uniq_passeur_opening_chest', columns: ['session_id', 'finding_slot', 'location_id'], options: ['where' => "status <> 'failed'"])]
#[ORM\Index(name: 'idx_passeur_opening_world', columns: ['session_id', 'finding_slot', 'created_at'])]
#[ORM\Index(name: 'idx_passeur_opening_absent', columns: ['absent_user_id', 'session_id'])]
#[ORM\Index(name: 'idx_passeur_opening_event', columns: ['event_id'])]
final class PasseurOpening
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_OPENED = 'opened';
    public const string STATUS_FAILED = 'failed';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $id,
        #[ORM\Column(name: 'session_id', type: Types::STRING, length: 64)]
        private string $sessionId,
        #[ORM\Column(name: 'request_key', type: Types::STRING, length: 120)]
        private string $requestKey,
        #[ORM\Column(name: 'buyer_user_id', type: Types::STRING, length: 32)]
        private string $buyerUserId,
        #[ORM\Column(name: 'buyer_slot', type: Types::INTEGER)]
        private int $buyerSlot,
        #[ORM\Column(name: 'finding_slot', type: Types::INTEGER)]
        private int $findingSlot,
        #[ORM\Column(name: 'absent_user_id', type: Types::STRING, length: 32, nullable: true)]
        private ?string $absentUserId,
        #[ORM\Column(name: 'location_id', type: Types::BIGINT)]
        private int|string $locationId,
        #[ORM\Column(name: 'location_name', type: Types::STRING, length: 200)]
        private string $locationName,
        #[ORM\Column(name: 'item_name', type: Types::STRING, length: 200)]
        private string $itemName,
        /** `event` (an event's silver pelles) or `bronze` (a private run's). */
        #[ORM\Column(type: Types::STRING, length: 10)]
        private string $kind,
        #[ORM\Column(name: 'event_id', type: Types::STRING, length: 32, nullable: true)]
        private ?string $eventId,
        #[ORM\Column(name: 'run_id', type: Types::STRING, length: 32, nullable: true)]
        private ?string $runId,
        #[ORM\Column(type: Types::INTEGER)]
        private int $price,
        #[ORM\Column(type: Types::STRING, length: 10)]
        private string $status,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::INTEGER)]
        private int $share = 0,
        #[ORM\Column(name: 'failure_code', type: Types::STRING, length: 40, nullable: true)]
        private ?string $failureCode = null,
        #[ORM\Column(name: 'settled_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $settledAt = null,
        /** Story 52.5: the slot names at the time of the call, for the journals. */
        #[ORM\Column(name: 'buyer_name', type: Types::STRING, length: 100, options: ['default' => ''])]
        private string $buyerName = '',
        #[ORM\Column(name: 'absent_name', type: Types::STRING, length: 100, options: ['default' => ''])]
        private string $absentName = '',
        /** Story 52.5: when the absent player closed the notice listing it; null while it is still shown. */
        #[ORM\Column(name: 'absent_seen_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $absentSeenAt = null,
    ) {
    }

    public function markOpened(int $share, \DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_OPENED;
        $this->share = $share;
        $this->settledAt = $now;
    }

    public function markFailed(string $code, \DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_FAILED;
        $this->failureCode = $code;
        $this->settledAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getRequestKey(): string
    {
        return $this->requestKey;
    }

    public function getBuyerUserId(): string
    {
        return $this->buyerUserId;
    }

    public function getBuyerSlot(): int
    {
        return $this->buyerSlot;
    }

    public function getFindingSlot(): int
    {
        return $this->findingSlot;
    }

    public function getAbsentUserId(): ?string
    {
        return $this->absentUserId;
    }

    public function getLocationId(): int
    {
        return (int) $this->locationId;
    }

    public function getLocationName(): string
    {
        return $this->locationName;
    }

    public function getItemName(): string
    {
        return $this->itemName;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getShare(): int
    {
        return $this->share;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getFailureCode(): ?string
    {
        return $this->failureCode;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getBuyerName(): string
    {
        return $this->buyerName;
    }

    public function getAbsentName(): string
    {
        return $this->absentName;
    }

    public function getAbsentSeenAt(): ?\DateTimeImmutable
    {
        return $this->absentSeenAt;
    }
}
