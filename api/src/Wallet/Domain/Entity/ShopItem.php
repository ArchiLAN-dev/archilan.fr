<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A cosmetic on sale in the shop (story 41.7): a frame or a banner of the shop catalog, its price in gold pelles,
 * and for a seasonal item the window it is sold in. Story 41.12: its price and window can change, a pause stops the
 * sale until it resumes, and an item can be deleted for good - what was bought stays owned (ownership is per
 * cosmetic, not per item).
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_item')]
final class ShopItem
{
    public const string TYPE_FRAME = 'frame';
    public const string TYPE_BANNER = 'banner';
    public const array TYPES = [self::TYPE_FRAME, self::TYPE_BANNER];

    public const int MIN_PRICE = 1;
    public const int MAX_PRICE = 10000;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 12)]
        private string $type,
        #[ORM\Column(name: 'cosmetic_key', type: 'string', length: 64)]
        private string $cosmeticKey,
        #[ORM\Column(type: 'integer')]
        private int $price,
        #[ORM\Column(name: 'available_from', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $availableFrom,
        #[ORM\Column(name: 'available_until', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $availableUntil,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'retired_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $retiredAt = null,
    ) {
    }

    public static function list(string $type, string $cosmeticKey, int $price, ?\DateTimeImmutable $from, ?\DateTimeImmutable $until, \DateTimeImmutable $now): self
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \DomainException('shop_item_type_invalid');
        }
        self::assertTerms($price, $from, $until);

        return new self(bin2hex(random_bytes(16)), $type, $cosmeticKey, $price, $from, $until, $now);
    }

    /** Story 41.12: a new price and sale window (null bounds: no start, no end). */
    public function edit(int $price, ?\DateTimeImmutable $from, ?\DateTimeImmutable $until): void
    {
        self::assertTerms($price, $from, $until);
        $this->price = $price;
        $this->availableFrom = $from;
        $this->availableUntil = $until;
    }

    public function isOnSale(\DateTimeImmutable $now): bool
    {
        return null === $this->retiredAt
            && (null === $this->availableFrom || $this->availableFrom <= $now)
            && (null === $this->availableUntil || $now < $this->availableUntil);
    }

    /** Stops the sale until it resumes (the column keeps its story 41.7 name). */
    public function pause(\DateTimeImmutable $now): void
    {
        $this->retiredAt ??= $now;
    }

    public function resume(): void
    {
        $this->retiredAt = null;
    }

    public function isPaused(): bool
    {
        return null !== $this->retiredAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCosmeticKey(): string
    {
        return $this->cosmeticKey;
    }

    public function getPrice(): int
    {
        return $this->price;
    }

    public function getAvailableFrom(): ?\DateTimeImmutable
    {
        return $this->availableFrom;
    }

    public function getAvailableUntil(): ?\DateTimeImmutable
    {
        return $this->availableUntil;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    private static function assertTerms(int $price, ?\DateTimeImmutable $from, ?\DateTimeImmutable $until): void
    {
        if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
            throw new \DomainException('shop_item_price_invalid');
        }
        if (null !== $from && null !== $until && $until <= $from) {
            throw new \DomainException('shop_item_window_invalid');
        }
    }
}
