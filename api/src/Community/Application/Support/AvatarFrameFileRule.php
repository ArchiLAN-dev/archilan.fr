<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

/**
 * What an uploaded video frame must be (story 41.10). The API converts nothing: the files come prepared by the
 * recipe of story 30.46 (a light video on black, the common 512 px geometry). It checks what it can without ffmpeg:
 * the container of each video, the weight, and the exact size of the two images.
 */
final class AvatarFrameFileRule
{
    public const array ROLES = ['webm', 'mp4', 'poster', 'still'];
    public const int MAX_VIDEO_BYTES = 3 * 1024 * 1024;
    public const int MAX_IMAGE_BYTES = 1024 * 1024;
    public const int SIZE = 512;

    /** Null when the file fits its role, otherwise the reason, for the admin. */
    public static function refusal(string $role, string $bytes): ?string
    {
        return match ($role) {
            'webm' => match (true) {
                \strlen($bytes) > self::MAX_VIDEO_BYTES => 'La vidéo WebM dépasse 3 Mo.',
                !str_starts_with($bytes, "\x1A\x45\xDF\xA3") => "Ce n'est pas une vidéo WebM.",
                default => null,
            },
            'mp4' => match (true) {
                \strlen($bytes) > self::MAX_VIDEO_BYTES => 'La vidéo MP4 dépasse 3 Mo.',
                'ftyp' !== substr($bytes, 4, 4) => "Ce n'est pas une vidéo MP4.",
                default => null,
            },
            'poster', 'still' => self::imageRefusal($role, $bytes),
            default => 'Fichier inattendu.',
        };
    }

    private static function imageRefusal(string $role, string $bytes): ?string
    {
        $name = 'poster' === $role ? "L'aperçu" : "L'image fixe";
        if (\strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return sprintf('%s dépasse 1 Mo.', $name);
        }
        if (!str_starts_with($bytes, 'RIFF') || 'WEBP' !== substr($bytes, 8, 4)) {
            return sprintf("%s n'est pas une image WebP.", $name);
        }
        $size = @getimagesizefromstring($bytes);
        if (false === $size || self::SIZE !== $size[0] || self::SIZE !== $size[1]) {
            return sprintf('%s doit mesurer %d x %d pixels.', $name, self::SIZE, self::SIZE);
        }

        return null;
    }
}
