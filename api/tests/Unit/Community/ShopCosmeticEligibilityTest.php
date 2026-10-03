<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\ValueObject\AvatarFrame;
use App\Community\Domain\ValueObject\BannerPreset;
use PHPUnit\Framework\TestCase;

/**
 * Story 41.7: a shop frame or banner is for who bought it; the rest keep their rules.
 */
final class ShopCosmeticEligibilityTest extends TestCase
{
    public function testAShopFrameNeedsToBeOwnedEvenByAnAdmin(): void
    {
        $shop = ['comet'];

        self::assertFalse(AvatarFrame::allowedFor('comet', true, [], $shop));
        self::assertTrue(AvatarFrame::allowedFor('comet', false, ['comet'], $shop));
    }

    public function testTheOtherFramesKeepTheirRules(): void
    {
        self::assertTrue(AvatarFrame::allowedFor('gold', false, [], ['comet']), 'a classic frame stays free');
        self::assertFalse(AvatarFrame::allowedFor('fire', false, [], ['comet']), 'a legendary one stays for admins');
        self::assertTrue(AvatarFrame::allowedFor('fire', true, [], ['comet']));
    }

    public function testAShopBannerNeedsToBeOwned(): void
    {
        self::assertFalse(BannerPreset::allowedFor('starfield', [], ['starfield']));
        self::assertTrue(BannerPreset::allowedFor('starfield', ['starfield'], ['starfield']));
        self::assertTrue(BannerPreset::allowedFor('sunset', [], ['starfield']));
    }

    public function testTheShopCatalogStartsEmptyUntilMembersDrawSome(): void
    {
        self::assertSame([], AvatarFrame::SHOP);
        self::assertSame([], BannerPreset::SHOP);
    }
}
