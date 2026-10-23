<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\ValueObject\SessionConfig;
use App\SessionConfig\Domain\ValueObject\SessionConfigOverride;
use PHPUnit\Framework\TestCase;

/**
 * Story 27.12: whether players see their spheres rides on the session config, but never reaches the Archipelago server.
 */
final class SpheresVisibleSettingTest extends TestCase
{
    public function testEveryProfileStartsWithSpheresVisible(): void
    {
        foreach (SessionType::cases() as $type) {
            $config = SessionConfig::defaultsFor($type);

            self::assertTrue($config->server->spheresVisible, $type->value);
            self::assertTrue($config->toArray()['server']['spheresVisible']);
            self::assertArrayNotHasKey('spheresVisible', $config->server->toServerFlags());
        }
    }

    public function testAProfileStoredBeforeTheStoryShowsTheSpheres(): void
    {
        $array = SessionConfig::defaultsFor(SessionType::Weekly)->toArray();
        unset($array['server']['spheresVisible']);

        self::assertTrue(SessionConfig::fromArray($array)->server->spheresVisible);
    }

    public function testAStoredFalseRoundTrips(): void
    {
        $array = SessionConfig::defaultsFor(SessionType::Event)->toArray();
        $array['server']['spheresVisible'] = false;

        self::assertFalse(SessionConfig::fromArray($array)->server->spheresVisible);
    }

    public function testAPartyTurnsItsSpheresOff(): void
    {
        $override = SessionConfigOverride::fromArray(['spheresVisible' => false]);

        self::assertFalse(SessionConfig::defaultsFor(SessionType::Private)->withOverride($override)->server->spheresVisible);
        self::assertSame(['spheresVisible' => false], $override->toArray());
        self::assertFalse($override->isEmpty());
    }

    public function testAnOverrideWithoutTheKeyInheritsTheProfile(): void
    {
        $override = SessionConfigOverride::fromArray(['hintCost' => 5]);

        self::assertNull($override->spheresVisible);
        self::assertTrue(SessionConfig::defaultsFor(SessionType::Event)->withOverride($override)->server->spheresVisible);
    }
}
