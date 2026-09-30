<?php

declare(strict_types=1);

namespace App\Community\Domain\Service;

use App\Community\Domain\Enum\CustomImageRefusal;
use App\Community\Domain\Enum\CustomImageSlot;
use App\Community\Domain\Enum\ImageFormat;
use App\Community\Domain\ValueObject\InspectedImage;

/**
 * Who may put which image on a profile, and which image the profile shows (story 30.40).
 *
 * Upload: any member sets a still avatar; members and admins set a banner image; only an admin uses a GIF, and
 * animation only comes as a GIF. Display is decided at read time from the account's current status - nothing is
 * erased when it changes, so everything comes back with the status: a GIF freezes on its first frame (extracted
 * at upload) once the account is no longer admin, and a banner image disappears for someone who is neither
 * member nor admin, leaving their banner preset.
 */
final class CustomImageRule
{
    private const int MEGABYTE = 1024 * 1024;

    public static function maxBytes(CustomImageSlot $slot, ImageFormat $format): int
    {
        return CustomImageSlot::Avatar === $slot && ImageFormat::Gif !== $format ? 5 * self::MEGABYTE : 10 * self::MEGABYTE;
    }

    public static function refusal(CustomImageSlot $slot, ?InspectedImage $image, int $size, bool $isAdmin, bool $isMember): ?CustomImageRefusal
    {
        if (CustomImageSlot::Banner === $slot && !$isAdmin && !$isMember) {
            return CustomImageRefusal::NotAllowed;
        }
        if (null === $image) {
            return CustomImageRefusal::UnsupportedType;
        }
        if (ImageFormat::Gif === $image->format && !$isAdmin) {
            return CustomImageRefusal::GifAdminOnly;
        }
        if ($image->animated && ImageFormat::Gif !== $image->format) {
            return CustomImageRefusal::AnimationUnsupported;
        }
        if ($size > self::maxBytes($slot, $image->format)) {
            return CustomImageRefusal::TooLarge;
        }

        return null;
    }

    /**
     * @param string|null $stillKey the first frame of a GIF upload, null for a still image
     */
    public static function displayedAvatarKey(?string $key, ?string $stillKey, bool $isAdmin): ?string
    {
        if (null === $key) {
            return null;
        }

        return null !== $stillKey && !$isAdmin ? $stillKey : $key;
    }

    /**
     * @param string|null $stillKey the first frame of a GIF upload, null for a still image
     */
    public static function displayedBannerKey(?string $key, ?string $stillKey, bool $isAdmin, bool $isMember): ?string
    {
        if (null === $key) {
            return null;
        }
        if ($isAdmin) {
            return $key;
        }

        return $isMember ? ($stillKey ?? $key) : null;
    }
}
