<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\ChangeUserSlug;
use PHPUnit\Framework\TestCase;

final class ChangeUserSlugTest extends TestCase
{
    public function testSanitizeAcceptsValidSlugs(): void
    {
        self::assertSame('alice', ChangeUserSlug::sanitize('alice'));
        self::assertSame('my-name', ChangeUserSlug::sanitize('my-name'));
        self::assertSame('abc123', ChangeUserSlug::sanitize('abc123'));
        self::assertSame('alice', ChangeUserSlug::sanitize('  Alice  '), 'trims + lowercases');
    }

    public function testSanitizeRejectsBadSlugs(): void
    {
        self::assertNull(ChangeUserSlug::sanitize(''));
        self::assertNull(ChangeUserSlug::sanitize('ab'), 'too short');
        self::assertNull(ChangeUserSlug::sanitize(str_repeat('a', ChangeUserSlug::MAX_LENGTH + 1)), 'too long');
        self::assertNull(ChangeUserSlug::sanitize('my name'), 'space');
        self::assertNull(ChangeUserSlug::sanitize('my_name'), 'underscore');
        self::assertNull(ChangeUserSlug::sanitize('café'), 'accent');
        self::assertNull(ChangeUserSlug::sanitize('-abc'), 'leading hyphen');
        self::assertNull(ChangeUserSlug::sanitize('abc-'), 'trailing hyphen');
        self::assertNull(ChangeUserSlug::sanitize('a--b'), 'doubled hyphen');
    }
}
