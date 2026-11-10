<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\ValueObject\SessionConfig;
use App\SessionConfig\Domain\ValueObject\SessionConfigOverride;
use PHPUnit\Framework\TestCase;

/**
 * Story 52.9: the Passeur no longer waits for a player to be absent, so the absence delay is gone. A profile or an
 * override stored with it still loads, the value ignored.
 */
final class PasseurAbsenceSettingGoneTest extends TestCase
{
    public function testAProfileStoredWithTheDelayStillLoads(): void
    {
        $array = SessionConfig::defaultsFor(SessionType::Private)->toArray();
        $array['server']['passeurAbsenceMinutes'] = 5;

        $config = SessionConfig::fromArray($array);

        self::assertArrayNotHasKey('passeurAbsenceMinutes', $config->toArray()['server']);
    }

    public function testAnOverrideStoredWithTheDelayKeepsTheRest(): void
    {
        $override = SessionConfigOverride::fromArray(['passeurAbsenceMinutes' => 5, 'passeurDailyLimit' => 3]);

        self::assertSame(['passeurDailyLimit' => 3], $override->toArray());
        self::assertTrue(SessionConfigOverride::fromArray(['passeurAbsenceMinutes' => 5])->isEmpty());
    }
}
