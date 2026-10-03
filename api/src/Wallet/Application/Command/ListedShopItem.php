<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/** The item {@see ManageShop::list()} put on sale. */
final readonly class ListedShopItem
{
    public function __construct(public string $id)
    {
    }
}
