<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\Compatibility;
use App\SessionConfig\Domain\CountdownMode;
use App\SessionConfig\Domain\ReleaseCollectMode;
use App\SessionConfig\Domain\RemainingMode;
use App\SessionConfig\Domain\SessionServerConfig;
use PHPUnit\Framework\TestCase;

final class SessionServerConfigTest extends TestCase
{
    private function make(int $hintCost = 10, int $lcp = 1, int $autoShutdown = 0): SessionServerConfig
    {
        return new SessionServerConfig(
            releaseMode: ReleaseCollectMode::Disabled,
            collectMode: ReleaseCollectMode::Disabled,
            remainingMode: RemainingMode::Goal,
            disableItemCheat: true,
            hintCost: $hintCost,
            locationCheckPoints: $lcp,
            countdownMode: CountdownMode::Auto,
            autoShutdown: $autoShutdown,
            compatibility: Compatibility::Casual,
        );
    }

    public function testConstructValidKeepsValues(): void
    {
        $c = $this->make();
        self::assertSame(ReleaseCollectMode::Disabled, $c->releaseMode);
        self::assertTrue($c->disableItemCheat);
        self::assertNull($c->joinPassword);
    }

    public function testConstructRejectsHintCostOutOfRange(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_hint_cost');
        $this->make(hintCost: 101);
    }

    public function testConstructRejectsNegativeLocationCheckPoints(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_location_check_points');
        $this->make(lcp: -1);
    }

    public function testConstructRejectsNegativeAutoShutdown(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('invalid_auto_shutdown');
        $this->make(autoShutdown: -1);
    }

    public function testToServerFlagsMapsScalarsAndOmitsEmptyPassword(): void
    {
        $flags = $this->make()->toServerFlags();
        self::assertSame('disabled', $flags['releaseMode']);
        self::assertSame('goal', $flags['remainingMode']);
        self::assertSame(2, $flags['compatibility']);
        self::assertTrue($flags['disableItemCheat']);
        self::assertArrayNotHasKey('password', $flags);
    }

    public function testToServerFlagsIncludesJoinPasswordWhenSet(): void
    {
        $c = new SessionServerConfig(
            releaseMode: ReleaseCollectMode::Goal,
            collectMode: ReleaseCollectMode::Goal,
            remainingMode: RemainingMode::Goal,
            disableItemCheat: false,
            hintCost: 0,
            locationCheckPoints: 0,
            countdownMode: CountdownMode::Disabled,
            autoShutdown: 0,
            compatibility: Compatibility::Tournament,
            joinPassword: 's3cret',
        );
        self::assertSame('s3cret', $c->toServerFlags()['password']);
    }
}
