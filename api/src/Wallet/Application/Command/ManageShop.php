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
 * The admin side of the shop (story 41.7): put a shop cosmetic on sale, for good or for a season, and retire it.
 * Only the cosmetics the code catalog marks as sold in the shop can be listed - the free ones stay free.
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
     * @throws NotFoundException when the item does not exist
     */
    public function retire(string $itemId): void
    {
        $item = $this->shop->findItem($itemId);
        if (!$item instanceof ShopItem) {
            throw new NotFoundException('Article introuvable.', 'shop_item_not_found');
        }
        $item->retire($this->clock->now());
        $this->shop->saveItem($item);
    }
}
