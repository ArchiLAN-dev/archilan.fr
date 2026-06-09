<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\PlandoOption;
use App\SessionConfig\Domain\ReleaseCollectMode;
use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigOverride;
use App\SessionConfig\Domain\SessionType;
use App\SessionConfig\Domain\SpoilerLevel;
use PHPUnit\Framework\TestCase;

final class SessionConfigArrayTest extends TestCase
{
    public function testToArrayFromArrayRoundTrip(): void
    {
        $config = SessionConfig::defaultsFor(SessionType::Private)->withOverride(
            new SessionConfigOverride(
                releaseMode: ReleaseCollectMode::Enabled,
                hintCost: 42,
                joinPassword: 'pw',
                plandoOptions: [PlandoOption::Bosses, PlandoOption::Items],
                spoiler: SpoilerLevel::None,
            ),
        );

        $rebuilt = SessionConfig::fromArray($config->toArray());

        self::assertEquals($config->toArray(), $rebuilt->toArray());
        self::assertSame('pw', $rebuilt->server->joinPassword);
        self::assertSame(['bosses', 'items'], $rebuilt->toArray()['generation']['plandoOptions']);
    }

    public function testFromArrayRejectsBadEnum(): void
    {
        $bad = SessionConfig::defaultsFor(SessionType::Weekly)->toArray();
        $bad['server']['releaseMode'] = 'nope';

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_release_collect_mode');
        SessionConfig::fromArray($bad);
    }

    public function testFromArrayRejectsMissingSection(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_session_config');
        SessionConfig::fromArray(['server' => []]);
    }

    public function testFromArrayRejectsWrongScalarType(): void
    {
        $bad = SessionConfig::defaultsFor(SessionType::Weekly)->toArray();
        $bad['server']['hintCost'] = '10'; // string, not int

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_session_config');
        SessionConfig::fromArray($bad);
    }

    public function testOverrideArrayRoundTripOmitsNulls(): void
    {
        $override = new SessionConfigOverride(hintCost: 5, race: true);
        $array = $override->toArray();

        self::assertSame(['hintCost' => 5, 'race' => true], $array);
        self::assertSame(5, SessionConfigOverride::fromArray($array)->hintCost);
        self::assertTrue(SessionConfigOverride::fromArray($array)->race);
        self::assertNull(SessionConfigOverride::fromArray($array)->releaseMode);
    }
}
