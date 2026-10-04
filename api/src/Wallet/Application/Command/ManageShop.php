<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Support\ShopCatalog;
use App\Wallet\Domain\Entity\ShopItem;
use App\Wallet\Domain\Repository\ShopRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The admin side of the shop (story 41.7): put a shop cosmetic on sale, for good or for a season. Story 41.12:
 * change its price or window, pause and resume the sale, delete the item for good. Only the cosmetics the catalogs
 * mark as sold in the shop can be listed - the free ones stay free.
 */
final readonly class ManageShop
{
    public function __construct(
        private ShopRepositoryInterface $shop,
        private ClockInterface $clock,
        private ShopCatalog $catalog,
    ) {
    }

    /**
     * @throws ValidationException when the cosmetic is not a shop one, or the price or window is invalid
     */
    public function list(string $type, string $cosmeticKey, int $price, ?\DateTimeImmutable $from, ?\DateTimeImmutable $until): ListedShopItem
    {
        if (!\in_array($cosmeticKey, $this->catalog->sellable()[$type] ?? [], true)) {
            throw new ValidationException("Ce cosmétique n'est pas vendu en boutique.", ['cosmeticKey' => ['Cosmétique hors boutique.']], 'not_sellable');
        }
        try {
            $item = ShopItem::list($type, $cosmeticKey, $price, $from, $until, $this->clock->now());
        } catch (\DomainException $e) {
            throw new ValidationException(sprintf('Prix de %d à %d pelles, fin après le début.', ShopItem::MIN_PRICE, ShopItem::MAX_PRICE), [], $e->getMessage());
        }
        $this->shop->saveItem($item);

        return new ListedShopItem($item->getId());
    }

    /**
     * @throws NotFoundException   when the item does not exist
     * @throws ValidationException when the price or window is invalid
     */
    public function edit(string $itemId, int $price, ?\DateTimeImmutable $from, ?\DateTimeImmutable $until): void
    {
        $item = $this->item($itemId);
        try {
            $item->edit($price, $from, $until);
        } catch (\DomainException $e) {
            throw new ValidationException(sprintf('Prix de %d à %d pelles, fin après le début.', ShopItem::MIN_PRICE, ShopItem::MAX_PRICE), [], $e->getMessage());
        }
        $this->shop->saveItem($item);
    }

    /**
     * @throws NotFoundException when the item does not exist
     */
    public function pause(string $itemId): void
    {
        $item = $this->item($itemId);
        $item->pause($this->clock->now());
        $this->shop->saveItem($item);
    }

    /**
     * @throws NotFoundException when the item does not exist
     */
    public function resume(string $itemId): void
    {
        $item = $this->item($itemId);
        $item->resume();
        $this->shop->saveItem($item);
    }

    /**
     * Deletes the item for good. Its buyers keep the cosmetic and their ledger lines.
     *
     * @throws NotFoundException when the item does not exist
     */
    public function delete(string $itemId): void
    {
        $this->shop->deleteItem($this->item($itemId));
    }

    private function item(string $itemId): ShopItem
    {
        $item = $this->shop->findItem($itemId);
        if (!$item instanceof ShopItem) {
            throw new NotFoundException('Article introuvable.', 'shop_item_not_found');
        }

        return $item;
    }
}
