<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\SlotNameGenerator;
use PHPUnit\Framework\TestCase;

final class SlotNameGeneratorTest extends TestCase
{
    private SlotNameGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new SlotNameGenerator();
    }

    // ─── Abbreviation ─────────────────────────────────────────────────────────

    public function testTwoWordGameAbbreviates(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight'],
        ]);

        self::assertSame(['Alice_HK'], $result);
    }

    public function testFourWordGameTruncatesAbbrToThree(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'A Link to the Past'],
        ]);

        self::assertSame(['Alice_ALT'], $result);
    }

    public function testSingleWordGameUsesOneLetterAbbr(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Bob', 'archipelagoGameName' => 'Minecraft'],
        ]);

        self::assertSame(['Bob_M'], $result);
    }

    public function testEmptyGameNameFallsBackToUNK(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => ''],
        ]);

        self::assertSame(['Alice_UNK'], $result);
    }

    // ─── No collision ─────────────────────────────────────────────────────────

    public function testDifferentPlayersNoCollision(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight'],
            ['playerName' => 'Bob', 'archipelagoGameName' => 'Hollow Knight'],
        ]);

        self::assertSame(['Alice_HK', 'Bob_HK'], $result);
    }

    public function testSamePlayerDifferentGamesNoCollision(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight'],
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Minecraft'],
        ]);

        self::assertSame(['Alice_HK', 'Alice_M'], $result);
    }

    // ─── Collision resolution ─────────────────────────────────────────────────

    public function testCollisionAppendsIncrementingIndex(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight'],
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight'],
        ]);

        self::assertSame(['Alice_HK1', 'Alice_HK2'], $result);
    }

    public function testTripleCollisionAppendsIndices(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight'],
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight'],
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight'],
        ]);

        self::assertSame(['Alice_HK1', 'Alice_HK2', 'Alice_HK3'], $result);
    }

    // ─── Length constraints ───────────────────────────────────────────────────

    public function testResultIsAtMost16Chars(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'VeryLongPlayerNameThatExceedsLimit', 'archipelagoGameName' => 'Some Game'],
        ]);

        self::assertLessThanOrEqual(16, mb_strlen($result[0]));
    }

    public function testCollisionSuffixKeepsResultWithin16Chars(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'VeryLongPlayerNameThatExceedsLimit', 'archipelagoGameName' => 'Some Game'],
            ['playerName' => 'VeryLongPlayerNameThatExceedsLimit', 'archipelagoGameName' => 'Some Game'],
        ]);

        foreach ($result as $name) {
            self::assertLessThanOrEqual(16, mb_strlen($name), "Slot name '$name' exceeds 16 characters.");
        }
    }

    // ─── Player name sanitization ─────────────────────────────────────────────

    public function testSpecialCharsStrippedFromPlayerName(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Ém-il_ie!', 'archipelagoGameName' => 'Celeste'],
        ]);

        self::assertSame(['milie_C'], $result);
    }

    public function testEmptyPlayerNameFallsBackToPlayer(): void
    {
        $result = $this->generator->generate([
            ['playerName' => '!!!', 'archipelagoGameName' => 'Celeste'],
        ]);

        self::assertSame(['Player_C'], $result);
    }

    // ─── Empty input ──────────────────────────────────────────────────────────

    public function testEmptyInputReturnsEmpty(): void
    {
        self::assertSame([], $this->generator->generate([]));
    }
}
