<?php

declare(strict_types=1);

namespace App\Tests\Unit\Wallet;

use App\Wallet\Application\Support\MinimapperzSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MinimapperzSlugTest extends TestCase
{
    #[DataProvider('accepted')]
    public function testTheSlugIsKeptFromASlugOrALanAddress(string $input, string $slug): void
    {
        self::assertSame($slug, MinimapperzSlug::fromInput($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        yield 'slug' => ['archilan-test', 'archilan-test'];
        yield 'slug with spaces around' => ['  archilan_4  ', 'archilan_4'];
        yield 'lan address' => ['https://app.minimapperz.com/lan/archilan-test/', 'archilan-test'];
        yield 'lan address without trailing slash' => ['https://app.minimapperz.com/lan/archilan-test', 'archilan-test'];
        yield 'lan address with a query' => ['https://app.minimapperz.com/lan/archilan-test/?tab=ranking', 'archilan-test'];
    }

    #[DataProvider('refused')]
    public function testAnythingElseIsRefused(string $input): void
    {
        self::assertNull(MinimapperzSlug::fromInput($input));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces inside' => ['archi lan'];
        yield 'other address' => ['https://app.minimapperz.com/profile/someone'];
        yield 'path' => ['../admin'];
        yield 'too long' => [str_repeat('a', 101)];
    }
}
