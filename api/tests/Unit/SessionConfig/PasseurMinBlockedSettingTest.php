<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\ValueObject\SessionConfig;
use App\SessionConfig\Domain\ValueObject\SessionConfigOverride;
use PHPUnit\Framework\TestCase;

/** Story 52.9: how long a player stays in BK before the Passeur may be called. */
final class PasseurMinBlockedSettingTest extends TestCase
{
    public function testEveryProfileWaitsHalfAnHourByDefault(): void
    {
        foreach (SessionType::cases() as $type) {
            self::assertSame(30, SessionConfig::defaultsFor($type)->server->passeurMinBlockedMinutes, $type->value);
        }
    }

    public function testAProfileStoredBeforeTheStoryWaitsTheDefault(): void
    {
        $array = SessionConfig::defaultsFor(SessionType::Event)->toArray();
        unset($array['server']['passeurMinBlockedMinutes']);

        self::assertSame(30, SessionConfig::fromArray($array)->server->passeurMinBlockedMinutes);
    }

    public function testAPartySetsItsOwnDelay(): void
    {
        $override = SessionConfigOverride::fromArray(['passeurMinBlockedMinutes' => 0]);

        self::assertSame(['passeurMinBlockedMinutes' => 0], $override->toArray());
        self::assertSame(0, SessionConfig::defaultsFor(SessionType::Private)->withOverride($override)->server->passeurMinBlockedMinutes);
    }

    public function testADelayOutOfBoundsIsRefused(): void
    {
        $this->expectExceptionMessage('invalid_passeur_setting');

        SessionConfigOverride::fromArray(['passeurMinBlockedMinutes' => 1441]);
    }
}
