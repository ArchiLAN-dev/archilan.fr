<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Application\Support\ShopCatalog;
use App\Wallet\Domain\Entity\ShopItem;
use App\Wallet\Domain\Repository\ShopRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The shop as a member and as an admin see it (story 41.7). Story 41.12: the shop is open to visitors (nothing
 * owned), and the admin sees each item's sales.
 */
final readonly class ShopQuery
{
    public function __construct(
        private ShopRepositoryInterface $shop,
        private ClockInterface $clock,
        private ShopCatalog $catalog,
    ) {
    }

    /**
     * The items on sale now, and whether the member already owns each (nothing for a visitor).
     * Story 41.14: `price` is what a purchase costs right now, `regularPrice` the price outside any promotion, and
     * `promotion` the running one (null: none).
     *
     * @return list<array{id: string, type: string, cosmeticKey: string, price: int, regularPrice: int, promotion: array{price: int, endsAt: string, percent: int}|null, availableUntil: string|null, listedAt: string, owned: bool}>
     */
    public function catalog(?string $userId): array
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
                'price' => $item->priceAt($now),
                'regularPrice' => $item->getPrice(),
                'promotion' => self::runningPromotion($item, $now),
                'availableUntil' => $item->getAvailableUntil()?->format(\DATE_ATOM),
                'listedAt' => $item->getCreatedAt()->format(\DATE_ATOM),
                'owned' => null !== $userId && $this->shop->owns($userId, $item->getType(), $item->getCosmeticKey()),
            ];
        }

        return $items;
    }

    /**
     * Every item ever listed with its state, and the cosmetics that may be put on sale.
     *
     * @return array{items: list<array{id: string, type: string, cosmeticKey: string, price: int, availableFrom: string|null, availableUntil: string|null, status: string, sales: int, pelles: int, promotion: array{price: int, startsAt: string|null, endsAt: string, status: string}|null}>, sellable: array{frame: list<string>, banner: list<string>}}
     */
    public function admin(): array
    {
        $now = $this->clock->now();
        $sales = $this->shop->sales();
        $items = array_map(static fn (ShopItem $item): array => [
            'id' => $item->getId(),
            'type' => $item->getType(),
            'cosmeticKey' => $item->getCosmeticKey(),
            'price' => $item->getPrice(),
            'availableFrom' => $item->getAvailableFrom()?->format(\DATE_ATOM),
            'availableUntil' => $item->getAvailableUntil()?->format(\DATE_ATOM),
            'status' => match (true) {
                $item->isPaused() => 'paused',
                $item->isOnSale($now) => 'on_sale',
                null !== $item->getAvailableFrom() && $now < $item->getAvailableFrom() => 'upcoming',
                default => 'ended',
            },
            'sales' => $sales[$item->getId()]['count'] ?? 0,
            'pelles' => $sales[$item->getId()]['pelles'] ?? 0,
            'promotion' => self::adminPromotion($item, $now),
        ], $this->shop->allItems());

        return ['items' => $items, 'sellable' => $this->catalog->sellable()];
    }

    /**
     * The promotion a member can use now, with its discount rounded to the percent.
     *
     * @return array{price: int, endsAt: string, percent: int}|null
     */
    private static function runningPromotion(ShopItem $item, \DateTimeImmutable $now): ?array
    {
        $price = $item->getPromoPrice();
        $endsAt = $item->getPromoEndsAt();
        if (!$item->isOnPromotion($now) || null === $price || null === $endsAt) {
            return null;
        }

        return [
            'price' => $price,
            'endsAt' => $endsAt->format(\DATE_ATOM),
            'percent' => (int) round(($item->getPrice() - $price) * 100 / $item->getPrice()),
        ];
    }

    /**
     * The promotion set on the item, whatever its state, for the admin to manage.
     *
     * @return array{price: int, startsAt: string|null, endsAt: string, status: string}|null
     */
    private static function adminPromotion(ShopItem $item, \DateTimeImmutable $now): ?array
    {
        $price = $item->getPromoPrice();
        $endsAt = $item->getPromoEndsAt();
        if (null === $price || null === $endsAt) {
            return null;
        }
        $startsAt = $item->getPromoStartsAt();

        return [
            'price' => $price,
            'startsAt' => $startsAt?->format(\DATE_ATOM),
            'endsAt' => $endsAt->format(\DATE_ATOM),
            'status' => match (true) {
                $item->isOnPromotion($now) => 'running',
                null !== $startsAt && $now < $startsAt => 'upcoming',
                default => 'ended',
            },
        ];
    }
}
