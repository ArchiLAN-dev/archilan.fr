<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A cosmetic on sale in the shop (story 41.7): a frame or a banner of the shop catalog, its price in gold pelles,
 * and for a seasonal item the window it is sold in. Retiring it stops the sale; what was bought stays owned.
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
        if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
            throw new \DomainException('shop_item_price_invalid');
        }
        if (null !== $from && null !== $until && $until <= $from) {
            throw new \DomainException('shop_item_window_invalid');
        }

        return new self(bin2hex(random_bytes(16)), $type, $cosmeticKey, $price, $from, $until, $now);
    }

    public function isOnSale(\DateTimeImmutable $now): bool
    {
        return null === $this->retiredAt
            && (null === $this->availableFrom || $this->availableFrom <= $now)
            && (null === $this->availableUntil || $now < $this->availableUntil);
    }

    public function retire(\DateTimeImmutable $now): void
    {
        $this->retiredAt ??= $now;
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

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }
}
