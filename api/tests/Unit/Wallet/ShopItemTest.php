<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Domain\Entity\ShopItem;
use PHPUnit\Framework\TestCase;

/** Story 41.14: a temporary promotion on a shop item. */
final class ShopItemTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-05T12:00:00+00:00');
    }

    public function testThePromotionalPriceAppliesOnlyBetweenItsDates(): void
    {
        $item = $this->item(80);
        $item->promote(60, self::at('2026-10-06'), self::at('2026-10-08'), $this->now);

        self::assertSame(80, $item->priceAt($this->now));
        self::assertFalse($item->isOnPromotion($this->now));
        self::assertSame(60, $item->priceAt(self::at('2026-10-07')));
        self::assertSame(80, $item->priceAt(self::at('2026-10-08')));
    }

    public function testAPromotionWithoutStartRunsRightAway(): void
    {
        $item = $this->item(80);
        $item->promote(60, null, self::at('2026-10-08'), $this->now);

        self::assertTrue($item->isOnPromotion($this->now));
        self::assertSame(60, $item->priceAt($this->now));

        $item->endPromotion();
        self::assertSame(80, $item->priceAt($this->now));
        self::assertNull($item->getPromoPrice());
    }

    public function testThePromotionalPriceMustBeUnderTheRegularOne(): void
    {
        $this->expectExceptionMessage('shop_item_promotion_price_invalid');
        $this->item(80)->promote(80, null, self::at('2026-10-08'), $this->now);
    }

    public function testThePromotionMustEndAfterItStarts(): void
    {
        $this->expectExceptionMessage('shop_item_promotion_window_invalid');
        $this->item(80)->promote(60, self::at('2026-10-08'), self::at('2026-10-07'), $this->now);
    }

    public function testAPromotionAlreadyOverIsRefused(): void
    {
        $this->expectExceptionMessage('shop_item_promotion_window_invalid');
        $this->item(80)->promote(60, null, self::at('2026-10-04'), $this->now);
    }

    public function testTheRegularPriceCannotDropToAPromotionToCome(): void
    {
        $item = $this->item(80);
        $item->promote(60, null, self::at('2026-10-08'), $this->now);

        $this->expectExceptionMessage('shop_item_price_below_promotion');
        $item->edit(60, null, null, $this->now);
    }

    public function testAnEndedPromotionNoLongerBlocksTheRegularPrice(): void
    {
        $item = $this->item(80);
        $item->promote(60, null, self::at('2026-10-08'), $this->now);

        $item->edit(50, null, null, self::at('2026-10-09'));
        self::assertSame(50, $item->getPrice());
    }

    private function item(int $price): ShopItem
    {
        return ShopItem::list(ShopItem::TYPE_FRAME, 'comet', $price, null, null, $this->now);
    }

    private static function at(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day.'T00:00:00+00:00');
    }
}
