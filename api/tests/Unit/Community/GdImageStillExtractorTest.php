<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Infrastructure\Adapter\GdImageStillExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Story 30.40. A GIF's first frame, as a PNG of the same size: what a profile shows once its owner is no longer
 * admin. GD reads only the first image of a GIF, which is exactly the one wanted.
 */
final class GdImageStillExtractorTest extends TestCase
{
    public function testTheFirstFrameComesOutAsAPngOfTheSameSize(): void
    {
        $image = imagecreatetruecolor(6, 3);
        self::assertNotFalse($image);
        ob_start();
        imagegif($image);
        $gif = (string) ob_get_clean();

        $png = new GdImageStillExtractor()->firstFrameAsPng($gif);

        self::assertNotNull($png);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $size = getimagesizefromstring($png);
        self::assertIsArray($size);
        self::assertSame([6, 3], [$size[0], $size[1]]);
    }

    public function testAnUnreadableGifGivesNothing(): void
    {
        self::assertNull(new GdImageStillExtractor()->firstFrameAsPng('GIF89a broken'));
    }
}
