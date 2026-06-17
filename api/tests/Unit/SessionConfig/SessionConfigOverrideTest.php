<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\ReleaseCollectMode;
use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigOverride;
use App\SessionConfig\Domain\SessionType;
use PHPUnit\Framework\TestCase;

final class SessionConfigOverrideTest extends TestCase
{
    public function testFromConfigRoundTripsThroughArray(): void
    {
        // fromConfig captures every field → toArray emits them all → fromArray reads them back.
        $override = SessionConfigOverride::fromConfig(SessionConfig::defaultsFor(SessionType::Private));

        self::assertFalse($override->isEmpty());
        self::assertNotSame([], $override->toArray());
        self::assertSame($override->toArray(), SessionConfigOverride::fromArray($override->toArray())->toArray());
    }

    public function testEmptyOverrideEmitsNothing(): void
    {
        $override = new SessionConfigOverride();

        self::assertTrue($override->isEmpty());
        self::assertSame([], $override->toArray());
    }

    public function testToArrayOmitsNullFields(): void
    {
        $array = (new SessionConfigOverride(releaseMode: ReleaseCollectMode::Goal))->toArray();

        self::assertSame(['releaseMode' => ReleaseCollectMode::Goal->value], $array);
    }

    public function testFromArrayRejectsNonArrayPlandoOptions(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_plando_option');

        SessionConfigOverride::fromArray(['plandoOptions' => 'not-an-array']);
    }

    public function testFromArrayRejectsNonStringPlandoOptionEntry(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_plando_option');

        SessionConfigOverride::fromArray(['plandoOptions' => [123]]);
    }

    public function testFromArrayIgnoresWronglyTypedScalarFields(): void
    {
        // A malformed blob (wrong types) yields an empty override rather than throwing.
        $override = SessionConfigOverride::fromArray([
            'hintCost' => 'not-an-int',
            'disableItemCheat' => 'nope',
            'releaseMode' => 42,
        ]);

        self::assertTrue($override->isEmpty());
    }
}
