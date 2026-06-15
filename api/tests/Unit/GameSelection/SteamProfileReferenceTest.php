<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\SteamProfileReference;
use PHPUnit\Framework\TestCase;

final class SteamProfileReferenceTest extends TestCase
{
    public function testParsesBareSteamId64(): void
    {
        $ref = SteamProfileReference::parse('76561197960287930');

        self::assertNotNull($ref);
        self::assertSame(SteamProfileReference::KIND_STEAMID64, $ref->kind);
        self::assertSame('76561197960287930', $ref->value);
    }

    public function testParsesProfilesUrl(): void
    {
        $ref = SteamProfileReference::parse('https://steamcommunity.com/profiles/76561197960287930/');

        self::assertNotNull($ref);
        self::assertSame(SteamProfileReference::KIND_STEAMID64, $ref->kind);
        self::assertSame('76561197960287930', $ref->value);
    }

    public function testParsesVanityUrl(): void
    {
        $ref = SteamProfileReference::parse('https://steamcommunity.com/id/Gabe_N/');

        self::assertNotNull($ref);
        self::assertSame(SteamProfileReference::KIND_VANITY, $ref->kind);
        self::assertSame('gabe_n', $ref->value);
    }

    public function testParsesUrlWithoutScheme(): void
    {
        $ref = SteamProfileReference::parse('steamcommunity.com/id/gaben');

        self::assertNotNull($ref);
        self::assertSame(SteamProfileReference::KIND_VANITY, $ref->kind);
        self::assertSame('gaben', $ref->value);
    }

    public function testParsesBareVanity(): void
    {
        $ref = SteamProfileReference::parse('gaben');

        self::assertNotNull($ref);
        self::assertSame(SteamProfileReference::KIND_VANITY, $ref->kind);
        self::assertSame('gaben', $ref->value);
    }

    public function testReturnsNullForEmptyInput(): void
    {
        self::assertNull(SteamProfileReference::parse('   '));
    }

    public function testReturnsNullForInvalidVanityCharacters(): void
    {
        self::assertNull(SteamProfileReference::parse('not a vanity!!'));
    }

    public function testReturnsNullForMalformedProfileUrl(): void
    {
        self::assertNull(SteamProfileReference::parse('https://steamcommunity.com/profiles/123'));
    }
}
