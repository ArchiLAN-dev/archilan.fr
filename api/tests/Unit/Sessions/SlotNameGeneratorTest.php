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

    public function testSpecialCharsStrippedButUnderscoreKept(): void
    {
        // Accents/dashes/punctuation are dropped; underscore is preserved.
        $result = $this->generator->generate([
            ['playerName' => 'Ém-il_ie!', 'archipelagoGameName' => 'Celeste'],
        ]);

        self::assertSame(['mil_ie_C'], $result);
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

    // ─── Preferred (custom) name - story 9.37 ─────────────────────────────────

    public function testLiteralPreferredNameIsHonored(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight', 'preferredName' => 'MasterKafey'],
        ]);

        self::assertSame(['MasterKafey'], $result);
    }

    public function testPlaceholderPreferredNameFallsBackToDerived(): void
    {
        // The unconfigured default `Player{number}` (and any AP-placeholder name) must not be honored.
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight', 'preferredName' => 'Player{number}'],
        ]);

        self::assertSame(['Alice_HK'], $result);
    }

    public function testEmptyOrInvalidPreferredNameFallsBackToDerived(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight', 'preferredName' => ''],
            ['playerName' => 'Bob', 'archipelagoGameName' => 'Minecraft', 'preferredName' => "O'Brien"],
            ['playerName' => 'Carol', 'archipelagoGameName' => 'Celeste', 'preferredName' => null],
        ]);

        self::assertSame(['Alice_HK', 'Bob_M', 'Carol_C'], $result);
    }

    public function testIdenticalPreferredNamesGetCollisionSuffixes(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight', 'preferredName' => 'Link'],
            ['playerName' => 'Bob', 'archipelagoGameName' => 'Minecraft', 'preferredName' => 'Link'],
        ]);

        self::assertSame(['Link1', 'Link2'], $result);
    }

    public function testPreferredNameCollidingWithDerivedGetsSuffixed(): void
    {
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight'],
            ['playerName' => 'Bob', 'archipelagoGameName' => 'Minecraft', 'preferredName' => 'Alice_HK'],
        ]);

        self::assertSame(['Alice_HK1', 'Alice_HK2'], $result);
    }

    public function testOverLongPreferredNameIsRejectedAndFallsBack(): void
    {
        // 17 chars > SlotName::MAX_LENGTH (16): not a valid slot name, so it falls back to the derived name.
        $result = $this->generator->generate([
            ['playerName' => 'Alice', 'archipelagoGameName' => 'Hollow Knight', 'preferredName' => 'AbcdefghijklmnopQ'],
        ]);

        self::assertSame(['Alice_HK'], $result);
    }
}
