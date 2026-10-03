<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Repository;

use App\Wallet\Domain\Entity\OwnedCosmetic;
use App\Wallet\Domain\Entity\ShopItem;

interface ShopRepositoryInterface
{
    public function findItem(string $id): ?ShopItem;

    /**
     * @return list<ShopItem> every item ever listed, newest first
     */
    public function allItems(): array;

    public function owns(string $userId, string $type, string $cosmeticKey): bool;

    /**
     * @return list<string> the cosmetic keys of that type the member owns
     */
    public function ownedKeys(string $userId, string $type): array;

    public function saveItem(ShopItem $item): void;

    public function saveOwned(OwnedCosmetic $owned): void;
}
