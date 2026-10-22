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
    public function testOnlyPrivateRunsStartWithHintsAndBountiesOn(): void
    {
        foreach (SessionType::cases() as $type) {
            $server = SessionConfig::defaultsFor($type)->server;
            // Story 41.38: paid in the run's own bronze, they are on by default in private runs only.
            $private = SessionType::Private === $type;

            self::assertSame($private, $server->pelleHints, $type->value);
            self::assertSame(20, $server->pelleItemHintPrice);
            self::assertSame(10, $server->pelleLocationHintPrice);
            self::assertSame($private, $server->pelleBounties, 'story 41.4');
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

    public function testTheBronzeSettingsDefaultAndOverride(): void
    {
        $server = SessionConfig::defaultsFor(SessionType::Private)->server;
        self::assertSame([20, 1, 25], [$server->bronzeStart, $server->bronzePerCheck, $server->bronzePerGoal], 'story 41.36');
        self::assertArrayNotHasKey('bronzeStart', $server->toServerFlags());

        $override = SessionConfigOverride::fromArray(['bronzePerCheck' => 0, 'bronzePerGoal' => 100]);
        $overridden = SessionConfig::defaultsFor(SessionType::Private)->withOverride($override)->server;

        self::assertSame([20, 0, 100], [$overridden->bronzeStart, $overridden->bronzePerCheck, $overridden->bronzePerGoal], '0 turns a source off');
        self::assertSame(['bronzePerCheck' => 0, 'bronzePerGoal' => 100], $override->toArray());
    }

    public function testAProfileStoredBeforeTheBronzeReadsItsDefaults(): void
    {
        $array = SessionConfig::defaultsFor(SessionType::Private)->toArray();
        unset($array['server']['bronzeStart'], $array['server']['bronzePerCheck'], $array['server']['bronzePerGoal']);

        $server = SessionConfig::fromArray($array)->server;

        self::assertSame([20, 1, 25], [$server->bronzeStart, $server->bronzePerCheck, $server->bronzePerGoal]);
    }

    public function testABronzeAmountOutOfBoundsIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_bronze_amount');

        SessionConfig::defaultsFor(SessionType::Private)->withOverride(new SessionConfigOverride(bronzeStart: 1001));
    }

    public function testAPriceOutOfBoundsIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_pelle_hint_price');

        SessionConfig::defaultsFor(SessionType::Private)->withOverride(new SessionConfigOverride(pelleItemHintPrice: 0));
    }
}
