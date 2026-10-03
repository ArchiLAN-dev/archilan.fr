<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Application\Support\ShopCatalog;
use App\Wallet\Domain\Entity\ShopItem;
use App\Wallet\Domain\Repository\ShopRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The shop as a member and as an admin see it (story 41.7).
 */
final readonly class ShopQuery
{
    public function __construct(
        private ShopRepositoryInterface $shop,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The items on sale now, and whether the member already owns each.
     *
     * @return list<array{id: string, type: string, cosmeticKey: string, price: int, availableUntil: string|null, owned: bool}>
     */
    public function catalog(string $userId): array
    {
        $now = $this->clock->now();
        $items = [];
        foreach ($this->shop->allItems() as $item) {
            if (!$item->isOnSale($now)) {
                continue;
            }
            $items[] = [
                'id' => $item->getId(),
                'type' => $item->getType(),
                'cosmeticKey' => $item->getCosmeticKey(),
                'price' => $item->getPrice(),
                'availableUntil' => $item->getAvailableUntil()?->format(\DATE_ATOM),
                'owned' => $this->shop->owns($userId, $item->getType(), $item->getCosmeticKey()),
            ];
        }

        return $items;
    }

    /**
     * Every item ever listed with its state, and the cosmetics that may be put on sale.
     *
     * @return array{items: list<array{id: string, type: string, cosmeticKey: string, price: int, availableFrom: string|null, availableUntil: string|null, status: string}>, sellable: array{frame: list<string>, banner: list<string>}}
     */
    public function admin(): array
    {
        $now = $this->clock->now();
        $items = array_map(static fn (ShopItem $item): array => [
            'id' => $item->getId(),
            'type' => $item->getType(),
            'cosmeticKey' => $item->getCosmeticKey(),
            'price' => $item->getPrice(),
            'availableFrom' => $item->getAvailableFrom()?->format(\DATE_ATOM),
            'availableUntil' => $item->getAvailableUntil()?->format(\DATE_ATOM),
            'status' => match (true) {
                null !== $item->getRetiredAt() => 'retired',
                $item->isOnSale($now) => 'on_sale',
                null !== $item->getAvailableFrom() && $now < $item->getAvailableFrom() => 'upcoming',
                default => 'ended',
            },
        ], $this->shop->allItems());

        return ['items' => $items, 'sellable' => ShopCatalog::sellable()];
    }
}
