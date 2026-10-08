<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\ArchipelagoSlotNameResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Story 17.29: the slot name a weekly player types in their client is what Archipelago made of the
 * template's YAML name in a one-player world.
 */
final class ArchipelagoSlotNameResolverTest extends TestCase
{
    /** @return iterable<string, array{string|null, string}> */
    public static function names(): iterable
    {
        yield 'a literal name is kept' => ['ArchiLAN', 'ArchiLAN'];
        yield 'lowercase number counts from 1' => ['Player{number}', 'Player1'];
        yield 'lowercase player is slot 1' => ['Run_{player}', 'Run_1'];
        yield 'uppercase variants only print from 2' => ['Solo{NUMBER}{PLAYER}', 'Solo'];
        yield 'legacy percent spelling' => ['Player%number%', 'Player1'];
        yield 'cut to 16 characters' => ['AVeryLongSlotNameIndeed', 'AVeryLongSlotNam'];
        yield 'no name falls back to the default' => [null, 'Player1'];
        yield 'blank name falls back to the default' => ['  ', 'Player1'];
    }

    #[DataProvider('names')]
    public function testSoloWorldResolvesLikeArchipelago(?string $yamlName, string $expected): void
    {
        self::assertSame($expected, ArchipelagoSlotNameResolver::soloWorld($yamlName));
    }
}
