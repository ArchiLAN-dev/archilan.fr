<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

/**
 * What an uploaded banner must be (story 41.11). The API converts nothing: the files come prepared. It checks what
 * it can without ffmpeg: the format and size of the still image, the container and weight of each video.
 */
final class ProfileBannerFileRule
{
    public const int MAX_IMAGE_BYTES = 2 * 1024 * 1024;
    public const int MAX_VIDEO_BYTES = 6 * 1024 * 1024;
    public const int MIN_WIDTH = 1200;
    public const int MIN_HEIGHT = 300;
    public const int MAX_WIDTH = 2400;
    public const int MAX_HEIGHT = 800;

    /** The extension of a still image, null when it is neither WebP nor JPEG. */
    public static function imageExtension(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, 'RIFF') && 'WEBP' === substr($bytes, 8, 4) => 'webp',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpg',
            default => null,
        };
    }

    /** Null when the still image fits, otherwise the reason, for the admin. */
    public static function imageRefusal(string $bytes): ?string
    {
        if ('' === $bytes) {
            return "L'image fixe est obligatoire.";
        }
        if (\strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return "L'image fixe dépasse 2 Mo.";
        }
        if (null === self::imageExtension($bytes)) {
            return "L'image fixe doit être en WebP ou en JPEG.";
        }
        $size = @getimagesizefromstring($bytes);
        if (false === $size) {
            return "L'image fixe est illisible.";
        }
        [$width, $height] = $size;
        if ($width < self::MIN_WIDTH || $height < self::MIN_HEIGHT || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT) {
            return sprintf("L'image fixe doit mesurer entre %d x %d et %d x %d pixels.", self::MIN_WIDTH, self::MIN_HEIGHT, self::MAX_WIDTH, self::MAX_HEIGHT);
        }
        if ($width < 3 * $height || $width > 6 * $height) {
            return "L'image fixe doit être de 3 à 6 fois plus large que haute.";
        }

        return null;
    }

    /** Null when the video fits its role (webm or mp4), otherwise the reason. */
    public static function videoRefusal(string $role, string $bytes): ?string
    {
        $name = 'webm' === $role ? 'WebM' : 'MP4';
        if (\strlen($bytes) > self::MAX_VIDEO_BYTES) {
            return sprintf('La vidéo %s dépasse 6 Mo.', $name);
        }
        $valid = 'webm' === $role ? str_starts_with($bytes, "\x1A\x45\xDF\xA3") : 'ftyp' === substr($bytes, 4, 4);

        return $valid ? null : sprintf("Ce n'est pas une vidéo %s.", $name);
    }
}
