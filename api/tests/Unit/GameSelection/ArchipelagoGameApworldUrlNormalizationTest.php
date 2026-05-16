<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Game;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArchipelagoGameApworldUrlNormalizationTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function validUrlProvider(): array
    {
        return [
            'canonical' => ['https://github.com/owner/repo', 'https://github.com/owner/repo'],
            'trailing slash' => ['https://github.com/owner/repo/', 'https://github.com/owner/repo'],
            'releases' => ['https://github.com/owner/repo/releases', 'https://github.com/owner/repo/releases'],
            'releases trailing slash' => ['https://github.com/owner/repo/releases/', 'https://github.com/owner/repo/releases'],
            'releases/latest' => ['https://github.com/owner/repo/releases/latest', 'https://github.com/owner/repo/releases/latest'],
            'releases/tag' => ['https://github.com/owner/repo/releases/tag/v1.0.0', 'https://github.com/owner/repo/releases/tag/v1.0.0'],
            'tree/branch' => ['https://github.com/owner/repo/tree/main', 'https://github.com/owner/repo/tree/main'],
            'query param preserved' => ['https://github.com/owner/repo?foo=bar', 'https://github.com/owner/repo?foo=bar'],
            'releases with query filter' => ['https://github.com/Happyhappyism/Archipelago/releases?q=%22ActRaiser%22&expanded=true', 'https://github.com/Happyhappyism/Archipelago/releases?q=%22ActRaiser%22&expanded=true'],
            'fragment stripped' => ['https://github.com/owner/repo#readme', 'https://github.com/owner/repo'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrlProvider(): array
    {
        return [
            'http scheme' => ['http://github.com/owner/repo'],
            'no scheme' => ['github.com/owner/repo'],
            'enterprise domain' => ['https://github.company.com/owner/repo'],
            'issues path' => ['https://github.com/owner/repo/issues'],
            'pulls path' => ['https://github.com/owner/repo/pulls'],
            'tag with extra parts' => ['https://github.com/owner/repo/releases/tag/v1.0.0/extra'],
            'only owner' => ['https://github.com/owner'],
            'empty' => [''],
        ];
    }

    #[DataProvider('validUrlProvider')]
    public function testValidUrlsAreNormalized(string $input, string $expected): void
    {
        self::assertSame($expected, Game::normalizeApworldSourceUrl($input));
    }

    #[DataProvider('invalidUrlProvider')]
    public function testInvalidUrlsReturnNull(string $input): void
    {
        self::assertNull(Game::normalizeApworldSourceUrl($input));
    }
}
