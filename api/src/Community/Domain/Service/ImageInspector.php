<?php

declare(strict_types=1);

namespace App\Community\Domain\Service;

use App\Community\Domain\Enum\ImageFormat;
use App\Community\Domain\ValueObject\InspectedImage;

/**
 * Reads an uploaded image's format, and whether it animates, from its bytes (story 30.40) - never from its name
 * or declared type. Animation matters because only an admin's GIF may move: an APNG (`acTL` chunk) or an
 * animated WebP (VP8X animation flag) must not slip a moving image past that rule.
 */
final class ImageInspector
{
    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const int WEBP_ANIMATION_FLAG = 0x02;

    public static function inspect(string $bytes): ?InspectedImage
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return new InspectedImage(ImageFormat::Jpeg, false);
        }
        if (str_starts_with($bytes, self::PNG_SIGNATURE)) {
            return new InspectedImage(ImageFormat::Png, self::pngAnimates($bytes));
        }
        if (str_starts_with($bytes, 'RIFF') && 'WEBP' === substr($bytes, 8, 4)) {
            return new InspectedImage(ImageFormat::Webp, self::webpAnimates($bytes));
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            $frames = self::gifFrameCount($bytes);

            return null === $frames || 0 === $frames ? null : new InspectedImage(ImageFormat::Gif, $frames > 1);
        }

        return null;
    }

    /** An APNG declares its animation (`acTL`) before its first image data. */
    private static function pngAnimates(string $bytes): bool
    {
        $offset = strlen(self::PNG_SIGNATURE);
        $length = strlen($bytes);
        while ($offset + 8 <= $length) {
            $size = self::uint32be($bytes, $offset);
            $type = substr($bytes, $offset + 4, 4);
            if ('acTL' === $type) {
                return true;
            }
            if ('IDAT' === $type || 'IEND' === $type) {
                return false;
            }
            $offset += 12 + $size;
        }

        return false;
    }

    /** An animated WebP is an extended one (VP8X) with its animation flag set. */
    private static function webpAnimates(string $bytes): bool
    {
        return 'VP8X' === substr($bytes, 12, 4)
            && strlen($bytes) > 20
            && 0 !== (ord($bytes[20]) & self::WEBP_ANIMATION_FLAG);
    }

    /**
     * Walks the GIF blocks and counts its images; null when the structure is broken.
     */
    private static function gifFrameCount(string $bytes): ?int
    {
        $length = strlen($bytes);
        if ($length < 13) {
            return null;
        }

        $offset = 13;
        $flags = ord($bytes[10]);
        if (0 !== ($flags & 0x80)) {
            $offset += 3 * (2 ** (($flags & 0x07) + 1));
        }

        $frames = 0;
        while ($offset < $length) {
            $block = ord($bytes[$offset]);
            if (0x3B === $block) {
                return $frames;
            }
            if (0x21 === $block) {
                $offset = self::skipSubBlocks($bytes, $offset + 2);
            } elseif (0x2C === $block) {
                if ($offset + 10 > $length) {
                    return null;
                }
                $imageFlags = ord($bytes[$offset + 9]);
                $offset += 10;
                if (0 !== ($imageFlags & 0x80)) {
                    $offset += 3 * (2 ** (($imageFlags & 0x07) + 1));
                }
                $offset = self::skipSubBlocks($bytes, $offset + 1);
                ++$frames;
            } else {
                return null;
            }
            if (null === $offset) {
                return null;
            }
        }

        // No trailer: a truncated file still shows what it has.
        return $frames;
    }

    /** Skips a chain of data sub-blocks; returns the offset after its terminator, null when it runs off. */
    private static function skipSubBlocks(string $bytes, int $offset): ?int
    {
        $length = strlen($bytes);
        while ($offset < $length) {
            $size = ord($bytes[$offset]);
            ++$offset;
            if (0 === $size) {
                return $offset;
            }
            $offset += $size;
        }

        return null;
    }

    private static function uint32be(string $bytes, int $offset): int
    {
        $value = unpack('N', substr($bytes, $offset, 4));

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : 0;
    }
}
