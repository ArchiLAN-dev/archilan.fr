<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\SlotName;
use PHPUnit\Framework\TestCase;

final class SlotNameTest extends TestCase
{
    public function testAcceptsLettersDigitsUnderscore(): void
    {
        self::assertTrue(SlotName::isValid('Alice'));
        self::assertTrue(SlotName::isValid('Bob_123'));
        self::assertTrue(SlotName::isValid('my_long_name_16c'));
    }

    public function testAcceptsArchipelagoPlaceholders(): void
    {
        self::assertTrue(SlotName::isValid('Player{number}'));
        self::assertTrue(SlotName::isValid('{player}'));
        self::assertTrue(SlotName::isValid('p{NUMBER}'));
    }

    public function testRejectsSpecialCharacters(): void
    {
        self::assertFalse(SlotName::isValid("O'Brien"), 'apostrophe');
        self::assertFalse(SlotName::isValid('Émilie'), 'accent');
        self::assertFalse(SlotName::isValid('a b'), 'space');
        self::assertFalse(SlotName::isValid('a-b'), 'dash');
        self::assertFalse(SlotName::isValid('Player{rng}'), 'unknown placeholder');
    }

    public function testRejectsEmptyAndTooLong(): void
    {
        self::assertFalse(SlotName::isValid(''));
        self::assertFalse(SlotName::isValid(str_repeat('a', SlotName::MAX_LENGTH + 1)));
        self::assertTrue(SlotName::isValid(str_repeat('a', SlotName::MAX_LENGTH)));
    }
}
