<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A mutual friendship between two users: request -> accept/decline. A canonical, order-independent
 * `pairKey` enforces a single row per pair (unique index). `accepted` = mutual friends (story 30.7).
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_friendship')]
#[ORM\UniqueConstraint(name: 'uniq_community_friendship_pair', columns: ['pair_key'])]
#[ORM\Index(name: 'idx_community_friendship_addressee', columns: ['addressee_id', 'status'])]
#[ORM\Index(name: 'idx_community_friendship_requester', columns: ['requester_id', 'status'])]
final class Friendship
{
    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const DECLINED = 'declined';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'requester_id', type: 'string', length: 32)]
        private string $requesterId,
        #[ORM\Column(name: 'addressee_id', type: 'string', length: 32)]
        private string $addresseeId,
        #[ORM\Column(name: 'pair_key', type: 'string', length: 65)]
        private string $pairKey,
        #[ORM\Column(type: 'string', length: 16)]
        private string $status,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'responded_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $respondedAt = null,
    ) {
    }

    public static function request(string $requesterId, string $addresseeId, \DateTimeImmutable $now): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            $requesterId,
            $addresseeId,
            self::pairKey($requesterId, $addresseeId),
            self::PENDING,
            $now,
        );
    }

    /** Canonical, order-independent key for a pair of users. */
    public static function pairKey(string $a, string $b): string
    {
        $pair = [$a, $b];
        sort($pair);

        return implode(':', $pair);
    }

    public function accept(\DateTimeImmutable $now): void
    {
        $this->status = self::ACCEPTED;
        $this->respondedAt = $now;
    }

    public function decline(\DateTimeImmutable $now): void
    {
        $this->status = self::DECLINED;
        $this->respondedAt = $now;
    }

    /** Re-open a previously declined request from $requesterId. */
    public function reopen(string $requesterId, string $addresseeId, \DateTimeImmutable $now): void
    {
        $this->requesterId = $requesterId;
        $this->addresseeId = $addresseeId;
        $this->status = self::PENDING;
        $this->createdAt = $now;
        $this->respondedAt = null;
    }

    public function isPending(): bool
    {
        return self::PENDING === $this->status;
    }

    public function isAccepted(): bool
    {
        return self::ACCEPTED === $this->status;
    }

    public function isAddressee(string $userId): bool
    {
        return $this->addresseeId === $userId;
    }

    public function involves(string $userId): bool
    {
        return $this->requesterId === $userId || $this->addresseeId === $userId;
    }

    public function otherParty(string $userId): string
    {
        return $this->requesterId === $userId ? $this->addresseeId : $this->requesterId;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getRequesterId(): string
    {
        return $this->requesterId;
    }

    public function getAddresseeId(): string
    {
        return $this->addresseeId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPairKey(): string
    {
        return $this->pairKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }
}
