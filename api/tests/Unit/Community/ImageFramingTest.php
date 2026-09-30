<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\ValueObject\ImageFraming;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Story 30.43. The part of an uploaded image a profile shows: a point aimed at (percent of the image) and a zoom.
 */
final class ImageFramingTest extends TestCase
{
    public function testTheDefaultIsTheCentredWholeImage(): void
    {
        $framing = ImageFraming::centred();

        self::assertSame(['x' => 50, 'y' => 50, 'zoom' => 100], $framing->toArray());
        self::assertTrue($framing->isCentred());
        self::assertFalse(new ImageFraming(30, 50, 100)->isCentred());
        self::assertFalse(new ImageFraming(50, 50, 150)->isCentred());
    }

    public function testAValidInputIsRead(): void
    {
        $framing = ImageFraming::fromInput(['x' => 0, 'y' => 100, 'zoom' => 300]);

        self::assertNotNull($framing);
        self::assertSame(['x' => 0, 'y' => 100, 'zoom' => 300], $framing->toArray());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'not an object' => ['50,50,100'];
        yield 'x above 100' => [['x' => 101, 'y' => 50, 'zoom' => 100]];
        yield 'y below 0' => [['x' => 50, 'y' => -1, 'zoom' => 100]];
        yield 'zoom below 100' => [['x' => 50, 'y' => 50, 'zoom' => 99]];
        yield 'zoom above 300' => [['x' => 50, 'y' => 50, 'zoom' => 301]];
        yield 'a string' => [['x' => '50', 'y' => 50, 'zoom' => 100]];
        yield 'a float' => [['x' => 50, 'y' => 12.5, 'zoom' => 100]];
        yield 'incomplete' => [['x' => 50, 'y' => 50]];
    }

    #[DataProvider('invalidInputs')]
    public function testAnInvalidInputIsRefused(mixed $input): void
    {
        self::assertNull(ImageFraming::fromInput($input));
    }

    public function testStoredValuesOutOfBoundsAreBroughtBackIn(): void
    {
        self::assertSame(['x' => 100, 'y' => 0, 'zoom' => 300], new ImageFraming(140, -5, 900)->toArray());
        self::assertSame(100, (new ImageFraming(50, 50, 20))->zoom);
    }
}
