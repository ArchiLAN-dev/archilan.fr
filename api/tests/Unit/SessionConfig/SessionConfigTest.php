<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\Compatibility;
use App\SessionConfig\Domain\PlandoOption;
use App\SessionConfig\Domain\ReleaseCollectMode;
use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigOverride;
use App\SessionConfig\Domain\SessionType;
use App\SessionConfig\Domain\SpoilerLevel;
use PHPUnit\Framework\TestCase;

final class SessionConfigTest extends TestCase
{
    public function testDefaultsWeeklyAndEventAreCompetitive(): void
    {
        foreach ([SessionType::Weekly, SessionType::Event] as $type) {
            $c = SessionConfig::defaultsFor($type);
            self::assertSame(ReleaseCollectMode::Disabled, $c->server->releaseMode, $type->value);
            self::assertSame(ReleaseCollectMode::Disabled, $c->server->collectMode, $type->value);
            self::assertTrue($c->server->disableItemCheat, $type->value);
            self::assertFalse($c->generation->race, $type->value);
        }
    }

    public function testDefaultsPrivateIsLax(): void
    {
        $c = SessionConfig::defaultsFor(SessionType::Private);
        self::assertSame(ReleaseCollectMode::Goal, $c->server->releaseMode);
        self::assertSame(ReleaseCollectMode::Goal, $c->server->collectMode);
        self::assertFalse($c->server->disableItemCheat);
        self::assertSame(SpoilerLevel::WithPaths, $c->generation->spoiler);
    }

    public function testWithOverrideReplacesOnlyProvidedFields(): void
    {
        $base = SessionConfig::defaultsFor(SessionType::Weekly);
        $merged = $base->withOverride(new SessionConfigOverride(
            releaseMode: ReleaseCollectMode::Goal,
            hintCost: 25,
            compatibility: Compatibility::Tournament,
            plandoOptions: [PlandoOption::Bosses],
            spoiler: SpoilerLevel::None,
        ));

        // Overridden:
        self::assertSame(ReleaseCollectMode::Goal, $merged->server->releaseMode);
        self::assertSame(25, $merged->server->hintCost);
        self::assertSame(Compatibility::Tournament, $merged->server->compatibility);
        self::assertSame([PlandoOption::Bosses], $merged->generation->plandoOptions);
        self::assertSame(SpoilerLevel::None, $merged->generation->spoiler);

        // Inherited from the profile (not in the override):
        self::assertSame(ReleaseCollectMode::Disabled, $merged->server->collectMode);
        self::assertTrue($merged->server->disableItemCheat);
        self::assertSame(1, $merged->server->locationCheckPoints);

        // Base is unchanged (immutability):
        self::assertSame(ReleaseCollectMode::Disabled, $base->server->releaseMode);
        self::assertSame(10, $base->server->hintCost);
    }

    public function testWithOverrideNeverChangesAutoShutdown(): void
    {
        // autoShutdown is locked to the type profile (story 27.9): a stored override carrying
        // autoShutdown=0 must NOT disable a private run's idle shutdown. Regression for the hotfix
        // where such a stale override left private runs running indefinitely.
        $base = SessionConfig::defaultsFor(SessionType::Private);
        self::assertSame(1800, $base->server->autoShutdown);

        $merged = $base->withOverride(new SessionConfigOverride(
            hintCost: 5,
            autoShutdown: 0,
        ));

        self::assertSame(5, $merged->server->hintCost);
        self::assertSame(1800, $merged->server->autoShutdown);
    }

    public function testWithEmptyOverrideIsEquivalentToProfile(): void
    {
        $base = SessionConfig::defaultsFor(SessionType::Event);
        $merged = $base->withOverride(new SessionConfigOverride());

        self::assertTrue((new SessionConfigOverride())->isEmpty());
        self::assertEquals($base->server->toServerFlags(), $merged->server->toServerFlags());
        self::assertEquals($base->generation->toGenerationParams(), $merged->generation->toGenerationParams());
    }
}
