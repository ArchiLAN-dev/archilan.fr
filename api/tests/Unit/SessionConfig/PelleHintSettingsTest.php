<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\ValueObject\SessionConfig;
use App\SessionConfig\Domain\ValueObject\SessionConfigOverride;
use PHPUnit\Framework\TestCase;

/**
 * Story 41.3: the "hints for pelles" settings ride on the session config, but never reach the Archipelago server.
 */
final class PelleHintSettingsTest extends TestCase
{
    public function testEveryProfileStartsDisabledWithTheSitePrices(): void
    {
        foreach (SessionType::cases() as $type) {
            $server = SessionConfig::defaultsFor($type)->server;

            self::assertFalse($server->pelleHints, $type->value);
            self::assertSame(20, $server->pelleItemHintPrice);
            self::assertSame(10, $server->pelleLocationHintPrice);
            self::assertFalse($server->pelleBounties, 'story 41.4');
        }
    }

    public function testTheSettingsAreInTheConfigButNotInTheServerFlags(): void
    {
        $config = SessionConfig::defaultsFor(SessionType::Weekly);

        $array = $config->toArray();
        self::assertFalse($array['server']['pelleHints']);
        self::assertSame(20, $array['server']['pelleItemHintPrice']);
        self::assertArrayNotHasKey('pelleHints', $config->server->toServerFlags());
        self::assertArrayNotHasKey('pelleItemHintPrice', $config->server->toServerFlags());
    }

    public function testAProfileStoredBeforeTheStoryReadsTheDefaults(): void
    {
        $array = SessionConfig::defaultsFor(SessionType::Event)->toArray();
        unset($array['server']['pelleHints'], $array['server']['pelleItemHintPrice'], $array['server']['pelleLocationHintPrice']);

        $server = SessionConfig::fromArray($array)->server;

        self::assertFalse($server->pelleHints);
        self::assertSame(20, $server->pelleItemHintPrice);
        self::assertSame(10, $server->pelleLocationHintPrice);
    }

    public function testAPartyOverridesItsOwnSettings(): void
    {
        $override = SessionConfigOverride::fromArray(['pelleHints' => true, 'pelleItemHintPrice' => 35]);

        $server = SessionConfig::defaultsFor(SessionType::Private)->withOverride($override)->server;

        self::assertTrue($server->pelleHints);
        self::assertSame(35, $server->pelleItemHintPrice);
        self::assertSame(10, $server->pelleLocationHintPrice, 'not overridden, inherited');
        self::assertSame(['pelleHints' => true, 'pelleItemHintPrice' => 35], $override->toArray());
        self::assertFalse($override->isEmpty());
    }

    public function testAPriceOutOfBoundsIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_pelle_hint_price');

        SessionConfig::defaultsFor(SessionType::Private)->withOverride(new SessionConfigOverride(pelleItemHintPrice: 0));
    }
}
