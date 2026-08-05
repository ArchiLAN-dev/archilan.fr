<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\Enum\Compatibility;
use App\SessionConfig\Domain\Enum\CountdownMode;
use App\SessionConfig\Domain\Enum\ReleaseCollectMode;
use App\SessionConfig\Domain\Enum\RemainingMode;
use App\SessionConfig\Domain\ValueObject\SessionServerConfig;
use PHPUnit\Framework\TestCase;

/**
 * Story 16.13: three states where the launch path used to see two. "Not configured" and "no
 * password wanted" were both null, so asking for no password produced a random one.
 */
final class SessionServerConfigJoinPasswordTest extends TestCase
{
    /** Everything but the join password is fixed - it is the only axis under test. */
    private function config(?string $joinPassword): SessionServerConfig
    {
        return new SessionServerConfig(
            releaseMode: ReleaseCollectMode::Auto,
            collectMode: ReleaseCollectMode::Auto,
            remainingMode: RemainingMode::Goal,
            disableItemCheat: true,
            hintCost: 10,
            locationCheckPoints: 1,
            countdownMode: CountdownMode::Auto,
            autoShutdown: 0,
            compatibility: Compatibility::Casual,
            joinPassword: $joinPassword,
        );
    }

    public function testAnUnconfiguredPasswordFallsBackToTheFreshSecret(): void
    {
        self::assertSame('fresh', $this->config(null)->joinPasswordOr('fresh'));
    }

    public function testAConfiguredPasswordIsUsedAsIs(): void
    {
        self::assertSame('hunter2', $this->config('hunter2')->joinPasswordOr('fresh'));
    }

    /** The point of the story: an empty override means no password, not a random one. */
    public function testAnEmptyPasswordMeansNoneAtAll(): void
    {
        self::assertNull($this->config('')->joinPasswordOr('fresh'));
    }

    public function testAnEmptyPasswordWinsOverAReusableOne(): void
    {
        // Relaunching a server whose owner has since removed the password must drop it, not keep
        // handing out the secret players already had.
        self::assertNull($this->config('')->joinPasswordOr('fresh', 'previous'));
    }

    public function testAReusablePasswordIsPreferredToAFreshSecretWhenUnconfigured(): void
    {
        // A force-launch must not change the password under the players already connected.
        self::assertSame('previous', $this->config(null)->joinPasswordOr('fresh', 'previous'));
    }

    public function testAnEmptyReusablePasswordIsNotReused(): void
    {
        self::assertSame('fresh', $this->config(null)->joinPasswordOr('fresh', ''));
    }

    /** The server flags never carried an empty password either - the two agree. */
    public function testTheServerFlagsOmitThePasswordWheneverTheResolutionIsNull(): void
    {
        self::assertArrayNotHasKey('password', $this->config('')->toServerFlags());
        self::assertArrayNotHasKey('password', $this->config(null)->toServerFlags());
        self::assertSame('hunter2', $this->config('hunter2')->toServerFlags()['password']);
    }
}
