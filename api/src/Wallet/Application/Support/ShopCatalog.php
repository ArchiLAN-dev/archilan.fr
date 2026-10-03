<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

use App\Community\Domain\ValueObject\AvatarFrame;
use App\Community\Domain\ValueObject\BannerPreset;
use App\Wallet\Domain\Entity\ShopItem;

/**
 * The cosmetics the shop may sell (story 41.7): those the code catalog marks as shop ones. The free frames and
 * banners stay free; a new shop cosmetic is drawn by a member, added to the catalog, then put on sale.
 */
final class ShopCatalog
{
    /**
     * @return array{frame: list<string>, banner: list<string>}
     */
    public static function sellable(): array
    {
        return [ShopItem::TYPE_FRAME => AvatarFrame::SHOP, ShopItem::TYPE_BANNER => BannerPreset::SHOP];
    }
}
