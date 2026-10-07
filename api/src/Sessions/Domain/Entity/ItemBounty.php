<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Pelles offered by a player to whoever sends a given item to their slot (story 41.4). The pelles are held as soon
 * as the bounty is posted; the bounty ends paid (to the owner of the sending slot, minus the commission), refunded
 * (the item came but nobody earned it, or the session ended) or withdrawn by its poster.
 */
#[ORM\Entity]
#[ORM\Table(name: 'item_bounty')]
#[ORM\Index(name: 'idx_item_bounty_session', columns: ['session_id', 'status'])]
final class ItemBounty
{
    public const int MIN_AMOUNT = 10;
    public const int MAX_AMOUNT = 1000;
    public const int COMMISSION_PERCENT = 10;

    public const string STATUS_OPEN = 'open';
    public const string STATUS_PAID = 'paid';
    public const string STATUS_REFUNDED = 'refunded';
    public const string STATUS_WITHDRAWN = 'withdrawn';

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'session_id', type: 'string', length: 64)]
        private string $sessionId,
        #[ORM\Column(name: 'slot_name', type: 'string', length: 255)]
        private string $slotName,
        #[ORM\Column(name: 'item_name', type: 'string', length: 255)]
        private string $itemName,
        #[ORM\Column(type: 'integer')]
        private int $amount,
        #[ORM\Column(name: 'poster_id', type: 'string', length: 32)]
        private string $posterId,
        #[ORM\Column(type: 'string', length: 12)]
        private string $status,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'settled_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $settledAt = null,
        #[ORM\Column(name: 'winner_id', type: 'string', length: 32, nullable: true)]
        private ?string $winnerId = null,
    ) {
    }

    public static function post(string $sessionId, string $slotName, string $itemName, int $amount, string $posterId, \DateTimeImmutable $now): self
    {
        $itemName = trim($itemName);
        if ('' === $itemName) {
            throw new \DomainException('bounty_item_required');
        }
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            throw new \DomainException('bounty_amount_out_of_bounds');
        }

        return new self(bin2hex(random_bytes(16)), $sessionId, $slotName, $itemName, $amount, $posterId, self::STATUS_OPEN, $now);
    }

    /** What the winner receives: the amount minus the commission, which is destroyed. */
    public function reward(): int
    {
        return $this->amount - intdiv($this->amount * self::COMMISSION_PERCENT, 100);
    }

    public function pay(string $winnerId, \DateTimeImmutable $now): void
    {
        $this->settle(self::STATUS_PAID, $now);
        $this->winnerId = $winnerId;
    }

    public function refund(\DateTimeImmutable $now): void
    {
        $this->settle(self::STATUS_REFUNDED, $now);
    }

    public function withdraw(\DateTimeImmutable $now): void
    {
        $this->settle(self::STATUS_WITHDRAWN, $now);
    }

    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
    }

    private function settle(string $status, \DateTimeImmutable $now): void
    {
        if (!$this->isOpen()) {
            throw new \DomainException('bounty_not_open');
        }
        $this->status = $status;
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

    public function getSlotName(): string
    {
        return $this->slotName;
    }

    public function getItemName(): string
    {
        return $this->itemName;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getPosterId(): string
    {
        return $this->posterId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getWinnerId(): ?string
    {
        return $this->winnerId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSettledAt(): ?\DateTimeImmutable
    {
        return $this->settledAt;
    }
}
