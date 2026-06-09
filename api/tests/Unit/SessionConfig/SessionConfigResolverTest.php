<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Application\SessionConfigResolver;
use App\SessionConfig\Domain\ReleaseCollectMode;
use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigOverride;
use App\SessionConfig\Domain\SessionConfigOverrideRepositoryInterface;
use App\SessionConfig\Domain\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\SessionType;
use PHPUnit\Framework\TestCase;

final class SessionConfigResolverTest extends TestCase
{
    public function testResolveReturnsProfileWhenNoSessionId(): void
    {
        $profiles = $this->createMock(SessionConfigProfileRepositoryInterface::class);
        $profiles->method('get')->willReturn(SessionConfig::defaultsFor(SessionType::Weekly));
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);
        $overrides->expects(self::never())->method('find');

        $resolved = (new SessionConfigResolver($profiles, $overrides))->resolve(SessionType::Weekly);

        self::assertSame(ReleaseCollectMode::Disabled, $resolved->server->releaseMode);
    }

    public function testResolveMergesOverrideWhenPresent(): void
    {
        $profiles = $this->createMock(SessionConfigProfileRepositoryInterface::class);
        $profiles->method('get')->willReturn(SessionConfig::defaultsFor(SessionType::Weekly));
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);
        $overrides->method('find')->willReturn(new SessionConfigOverride(releaseMode: ReleaseCollectMode::Goal));

        $resolved = (new SessionConfigResolver($profiles, $overrides))->resolve(SessionType::Weekly, 'sess-1');

        self::assertSame(ReleaseCollectMode::Goal, $resolved->server->releaseMode);
        // Untouched field still from the profile:
        self::assertTrue($resolved->server->disableItemCheat);
    }

    public function testResolveIgnoresEmptyOverride(): void
    {
        $profiles = $this->createMock(SessionConfigProfileRepositoryInterface::class);
        $profiles->method('get')->willReturn(SessionConfig::defaultsFor(SessionType::Private));
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);
        $overrides->method('find')->willReturn(new SessionConfigOverride());

        $resolved = (new SessionConfigResolver($profiles, $overrides))->resolve(SessionType::Private, 'sess-2');

        self::assertSame(ReleaseCollectMode::Goal, $resolved->server->releaseMode);
    }

    public function testRecordResolvedForSessionStoresFullOverride(): void
    {
        $profiles = $this->createMock(SessionConfigProfileRepositoryInterface::class);
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);

        $captured = null;
        $overrides->expects(self::once())->method('save')
            ->willReturnCallback(static function (string $id, SessionConfigOverride $o) use (&$captured): void {
                $captured = $o;
            });

        $resolved = SessionConfig::defaultsFor(SessionType::Event);
        (new SessionConfigResolver($profiles, $overrides))->recordResolvedForSession('sess-3', $resolved);

        self::assertInstanceOf(SessionConfigOverride::class, $captured);
        self::assertFalse($captured->isEmpty());
        self::assertSame(ReleaseCollectMode::Disabled, $captured->releaseMode);
    }
}
