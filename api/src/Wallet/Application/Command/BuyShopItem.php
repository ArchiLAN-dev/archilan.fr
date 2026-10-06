<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Domain\Entity\OwnedCosmetic;
use App\Wallet\Domain\Entity\ShopItem;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\ShopRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * A member buys a cosmetic (story 41.7): the gold pelles and the ownership are written in one transaction. Buying
 * the same item twice is refused; the ledger key (one per member and item) also stops a double submit.
 */
final readonly class BuyShopItem
{
    public function __construct(
        private ShopRepositoryInterface $shop,
        private RecordPelleMovement $record,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException   when the item does not exist
     * @throws ConflictException   when it is not on sale, or already owned
     * @throws ForbiddenException  when the member is banned
     * @throws ValidationException when the member cannot pay
     */
    public function buy(string $userId, string $itemId, ?int $expectedPrice = null): void
    {
        $now = $this->clock->now();
        $item = $this->shop->findItem($itemId);
        if (!$item instanceof ShopItem) {
            throw new NotFoundException('Article introuvable.', 'shop_item_not_found');
        }
        if (!$item->isOnSale($now)) {
            throw new ConflictException("Cet article n'est pas en vente.", 'not_on_sale');
        }
        // Story 41.14: the member agreed to the price the page showed. A promotion ending in between, or a stale
        // page, must not charge them more without a word - a purchase is final.
        if (null !== $expectedPrice && $expectedPrice !== $item->priceAt($now)) {
            throw new ConflictException(sprintf('Le prix a changé : il est maintenant de %d pelles.', $item->priceAt($now)), 'price_changed');
        }
        if ($this->shop->owns($userId, $item->getType(), $item->getCosmeticKey())) {
            throw new ConflictException('Tu possèdes déjà cet article.', 'already_owned');
        }

        // Story 41.14: the price in force at this very instant, promotion included - never the one the page showed.
        $onPromotion = $item->isOnPromotion($now);
        $this->record->record(
            new RecordPelleMovementInput(
                $userId, -$item->priceAt($now), PelleKind::Gold, null, PelleReason::ShopPurchase,
                sprintf('Boutique : %s %s%s', match ($item->getType()) {
                    ShopItem::TYPE_FRAME => 'cadre',
                    ShopItem::TYPE_TITLE => 'titre',
                    default => 'bannière',
                }, $item->getCosmeticKey(), $onPromotion ? ' (en promotion)' : ''), null,
                sprintf('shop:%s:%s', $userId, $item->getId()),
            ),
            fn () => $this->shop->saveOwned(OwnedCosmetic::acquire($userId, $item->getType(), $item->getCosmeticKey(), $now)),
        );
    }
}
