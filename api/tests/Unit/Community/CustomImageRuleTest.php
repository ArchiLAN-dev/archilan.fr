<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Enum\CustomImageRefusal;
use App\Community\Domain\Enum\CustomImageSlot;
use App\Community\Domain\Enum\ImageFormat;
use App\Community\Domain\Service\CustomImageRule;
use App\Community\Domain\ValueObject\InspectedImage;
use PHPUnit\Framework\TestCase;

/**
 * Story 30.40. Who may upload which image, and which image a profile shows for the account's current status.
 */
final class CustomImageRuleTest extends TestCase
{
    private const int MB = 1024 * 1024;

    public function testAnyMemberUploadsAStillAvatarUpToFiveMegabytes(): void
    {
        self::assertNull($this->refusal(CustomImageSlot::Avatar, ImageFormat::Png, false, 5 * self::MB, admin: false, member: false));
        self::assertSame(CustomImageRefusal::TooLarge, $this->refusal(CustomImageSlot::Avatar, ImageFormat::Webp, false, 5 * self::MB + 1, admin: true, member: true));
    }

    public function testOnlyAnAdminUploadsAGifAvatarUpToTenMegabytes(): void
    {
        self::assertNull($this->refusal(CustomImageSlot::Avatar, ImageFormat::Gif, true, 10 * self::MB, admin: true, member: false));
        self::assertSame(CustomImageRefusal::GifAdminOnly, $this->refusal(CustomImageSlot::Avatar, ImageFormat::Gif, false, self::MB, admin: false, member: true));
        self::assertSame(CustomImageRefusal::TooLarge, $this->refusal(CustomImageSlot::Avatar, ImageFormat::Gif, true, 10 * self::MB + 1, admin: true, member: false));
    }

    public function testABannerImageIsForMembersAndAdminsOnly(): void
    {
        self::assertSame(CustomImageRefusal::NotAllowed, $this->refusal(CustomImageSlot::Banner, ImageFormat::Png, false, self::MB, admin: false, member: false));
        self::assertNull($this->refusal(CustomImageSlot::Banner, ImageFormat::Jpeg, false, 10 * self::MB, admin: false, member: true));
        self::assertNull($this->refusal(CustomImageSlot::Banner, ImageFormat::Gif, true, 10 * self::MB, admin: true, member: false));
        self::assertSame(CustomImageRefusal::GifAdminOnly, $this->refusal(CustomImageSlot::Banner, ImageFormat::Gif, false, self::MB, admin: false, member: true));
        self::assertSame(CustomImageRefusal::TooLarge, $this->refusal(CustomImageSlot::Banner, ImageFormat::Png, false, 10 * self::MB + 1, admin: false, member: true));
    }

    public function testAnimationOnlyComesAsAGif(): void
    {
        foreach ([CustomImageSlot::Avatar, CustomImageSlot::Banner] as $slot) {
            self::assertSame(CustomImageRefusal::AnimationUnsupported, $this->refusal($slot, ImageFormat::Webp, true, self::MB, admin: true, member: true));
            self::assertSame(CustomImageRefusal::AnimationUnsupported, $this->refusal($slot, ImageFormat::Png, true, self::MB, admin: true, member: true));
        }
    }

    public function testAnUnreadableFileIsAnUnsupportedType(): void
    {
        self::assertSame(CustomImageRefusal::UnsupportedType, CustomImageRule::refusal(CustomImageSlot::Avatar, null, 10, true, true));
    }

    public function testAnAvatarGifFreezesOnceTheAccountIsNoLongerAdmin(): void
    {
        self::assertSame('a.gif', CustomImageRule::displayedAvatarKey('a.gif', 'a.png', true));
        self::assertSame('a.png', CustomImageRule::displayedAvatarKey('a.gif', 'a.png', false));
        self::assertSame('a.webp', CustomImageRule::displayedAvatarKey('a.webp', null, false));
        self::assertNull(CustomImageRule::displayedAvatarKey(null, null, true));
    }

    public function testABannerFollowsTheStatusAndComesBackWithIt(): void
    {
        // Admin: the upload as it is.
        self::assertSame('b.gif', CustomImageRule::displayedBannerKey('b.gif', 'b.png', true, false));
        // Member but no longer admin: the first frame of a GIF, a still image as it is.
        self::assertSame('b.png', CustomImageRule::displayedBannerKey('b.gif', 'b.png', false, true));
        self::assertSame('b.jpg', CustomImageRule::displayedBannerKey('b.jpg', null, false, true));
        // Neither: no image, the preset shows.
        self::assertNull(CustomImageRule::displayedBannerKey('b.jpg', null, false, false));
        self::assertNull(CustomImageRule::displayedBannerKey(null, null, true, true));
    }

    public function testMaxSizesAreStated(): void
    {
        self::assertSame(5 * self::MB, CustomImageRule::maxBytes(CustomImageSlot::Avatar, ImageFormat::Png));
        self::assertSame(10 * self::MB, CustomImageRule::maxBytes(CustomImageSlot::Avatar, ImageFormat::Gif));
        self::assertSame(10 * self::MB, CustomImageRule::maxBytes(CustomImageSlot::Banner, ImageFormat::Jpeg));
    }

    private function refusal(CustomImageSlot $slot, ImageFormat $format, bool $animated, int $size, bool $admin, bool $member): ?CustomImageRefusal
    {
        return CustomImageRule::refusal($slot, new InspectedImage($format, $animated), $size, $admin, $member);
    }
}
