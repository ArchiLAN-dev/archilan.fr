<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

use App\Community\Application\Support\AvatarFrameCatalog;
use App\Community\Application\Support\ProfileBannerCatalog;
use App\Wallet\Domain\Entity\ShopItem;

/**
 * The cosmetics the shop may sell (story 41.7): those the code catalog marks as shop ones. The free frames and
 * banners stay free; a new shop cosmetic is drawn by a member, added to the catalog, then put on sale.
 */
final readonly class ShopCatalog
{
    public function __construct(
        private AvatarFrameCatalog $frames,
        private ProfileBannerCatalog $banners,
    ) {
    }

    /**
     * Stories 41.10 and 41.11: the frames and banners include those an admin uploaded for the shop.
     *
     * @return array{frame: list<string>, banner: list<string>}
     */
    public function sellable(): array
    {
        return [ShopItem::TYPE_FRAME => $this->frames->sellableKeys(), ShopItem::TYPE_BANNER => $this->banners->sellableKeys()];
    }
}
