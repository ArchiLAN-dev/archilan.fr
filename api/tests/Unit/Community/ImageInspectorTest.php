<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Enum\ImageFormat;
use App\Community\Domain\Service\ImageInspector;
use PHPUnit\Framework\TestCase;

/**
 * Story 30.40. The format and whether it animates come from the file's bytes, never its name: a GIF counts
 * its frames, an APNG carries an `acTL` chunk, an animated WebP sets the VP8X animation flag.
 */
final class ImageInspectorTest extends TestCase
{
    private const string PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testAStillPngJpegAndWebpAreRecognised(): void
    {
        $png = ImageInspector::inspect((string) base64_decode(self::PNG_1X1, true));
        self::assertSame(ImageFormat::Png, $png?->format);
        self::assertFalse($png->animated);

        $jpeg = ImageInspector::inspect("\xFF\xD8\xFF\xE0".str_repeat("\0", 20));
        self::assertSame(ImageFormat::Jpeg, $jpeg?->format);
        self::assertFalse($jpeg->animated);

        $webp = ImageInspector::inspect($this->riff('VP8 '.pack('V', 4)."\0\0\0\0"));
        self::assertSame(ImageFormat::Webp, $webp?->format);
        self::assertFalse($webp->animated);
    }

    public function testAnAnimatedPngIsSpottedByItsAnimationChunk(): void
    {
        $apng = "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', pack('NN', 1, 1)."\x08\x06\0\0\0")
            .$this->pngChunk('acTL', pack('NN', 2, 0))
            .$this->pngChunk('IDAT', 'xx')
            .$this->pngChunk('IEND', '');

        $image = ImageInspector::inspect($apng);

        self::assertSame(ImageFormat::Png, $image?->format);
        self::assertTrue($image->animated);
    }

    public function testAnAnimatedWebpIsSpottedByItsFlag(): void
    {
        $image = ImageInspector::inspect($this->riff('VP8X'.pack('V', 10)."\x02\0\0\0".str_repeat("\0", 6)));

        self::assertSame(ImageFormat::Webp, $image?->format);
        self::assertTrue($image->animated);
    }

    public function testAGifCountsItsFrames(): void
    {
        $one = ImageInspector::inspect($this->gif(1));
        self::assertSame(ImageFormat::Gif, $one?->format);
        self::assertFalse($one->animated);

        $two = ImageInspector::inspect($this->gif(2));
        self::assertSame(ImageFormat::Gif, $two?->format);
        self::assertTrue($two->animated);
    }

    public function testAnythingElseIsNotAnImageWeTake(): void
    {
        self::assertNull(ImageInspector::inspect('<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        self::assertNull(ImageInspector::inspect(''));
        self::assertNull(ImageInspector::inspect('GIF89a'));
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data."\0\0\0\0";
    }

    private function riff(string $chunks): string
    {
        return 'RIFF'.pack('V', 4 + strlen($chunks)).'WEBP'.$chunks;
    }

    /** A minimal GIF89a: no global palette, a control extension and one tiny image per frame. */
    private function gif(int $frames): string
    {
        $gif = 'GIF89a'.pack('vv', 1, 1)."\x00\x00\x00";
        for ($i = 0; $i < $frames; ++$i) {
            $gif .= "\x21\xF9\x04\x00\x0A\x00\x00\x00";
            $gif .= "\x2C".pack('vvvv', 0, 0, 1, 1)."\x00\x02\x02\x4C\x01\x00";
        }

        return $gif."\x3B";
    }
}
