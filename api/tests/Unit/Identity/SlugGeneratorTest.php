<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\SlugGenerator;
use PHPUnit\Framework\TestCase;

final class SlugGeneratorTest extends TestCase
{
    public function testNormalizeLowercases(): void
    {
        self::assertSame('jean', SlugGenerator::normalize('Jean'));
        self::assertSame('alice', SlugGenerator::normalize('ALICE'));
    }

    public function testNormalizeStripsAccents(): void
    {
        self::assertSame('andre', SlugGenerator::normalize('André'));
        self::assertSame('elodie', SlugGenerator::normalize('Élodie'));
        self::assertSame('remi', SlugGenerator::normalize('Rémi'));
    }

    public function testNormalizeReplacesSpacesWithHyphens(): void
    {
        self::assertSame('hello-world', SlugGenerator::normalize('Hello World'));
        self::assertSame('jean-marie', SlugGenerator::normalize('Jean-Marie'));
    }

    public function testNormalizeSpecialCharsToHyphens(): void
    {
        self::assertSame('cool-player', SlugGenerator::normalize('cool_player'));
    }

    public function testNormalizeEmptyFallsBackToUser(): void
    {
        self::assertSame('user', SlugGenerator::normalize(''));
        self::assertSame('user', SlugGenerator::normalize('---'));
    }

    public function testNormalizeTruncatesTo75Chars(): void
    {
        $long = str_repeat('a', 100);
        $result = SlugGenerator::normalize($long);
        self::assertSame(75, mb_strlen($result));
    }

    public function testGenerateNoCollision(): void
    {
        $slug = SlugGenerator::generate('Jean', fn (string $s) => false);
        self::assertSame('jean', $slug);
    }

    public function testGenerateCollisionAddsNumericSuffix(): void
    {
        $slug = SlugGenerator::generate('Jean', fn (string $s) => 'jean' === $s);
        self::assertSame('jean-2', $slug);
    }

    public function testGenerateMultipleCollisionsIncrementsCorrectly(): void
    {
        $used = ['jean', 'jean-2'];
        $slug = SlugGenerator::generate('Jean', fn (string $s) => in_array($s, $used, true));
        self::assertSame('jean-3', $slug);
    }
}
