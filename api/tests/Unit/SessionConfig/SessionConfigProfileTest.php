<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigProfile;
use App\SessionConfig\Domain\SessionType;
use PHPUnit\Framework\TestCase;

final class SessionConfigProfileTest extends TestCase
{
    public function testRoundTripsConfigThroughJsonBlob(): void
    {
        $now = new \DateTimeImmutable('2026-06-17 10:00:00');
        $config = SessionConfig::defaultsFor(SessionType::Weekly);

        $profile = new SessionConfigProfile(SessionType::Weekly->value, $config, $now);

        self::assertSame(SessionType::Weekly, $profile->type());
        self::assertSame($now, $profile->updatedAt());
        self::assertEquals($config->toArray(), $profile->toSessionConfig()->toArray());
    }

    public function testUpdateReplacesConfigAndBumpsUpdatedAt(): void
    {
        $now = new \DateTimeImmutable('2026-06-17 10:00:00');
        $profile = new SessionConfigProfile(SessionType::Private->value, SessionConfig::defaultsFor(SessionType::Private), $now);

        $later = $now->modify('+2 hours');
        $newConfig = SessionConfig::defaultsFor(SessionType::Event);
        $profile->update($newConfig, $later);

        self::assertSame($later, $profile->updatedAt());
        self::assertEquals($newConfig->toArray(), $profile->toSessionConfig()->toArray());
    }
}
