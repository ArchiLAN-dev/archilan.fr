<?php

declare(strict_types=1);

namespace App\Tests\Unit\Streaming;

use App\Streaming\Application\TwitchStatusChecker;
use App\Streaming\Infrastructure\TwitchApiClientInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class TwitchStatusCheckerTest extends TestCase
{
    public function testReturnsLiveStatusFromTwitchClient(): void
    {
        $checker = new TwitchStatusChecker(
            new FakeTwitchApiClient([42]),
            new ArrayAdapter(),
        );

        $status = $checker->check();

        self::assertTrue($status->live);
        self::assertSame(42, $status->viewerCount);
    }

    public function testApiFailureFallsBackToOfflineStatus(): void
    {
        $checker = new TwitchStatusChecker(
            new FakeTwitchApiClient([null]),
            new ArrayAdapter(),
        );

        $status = $checker->check();

        self::assertFalse($status->live);
        self::assertNull($status->viewerCount);
    }

    public function testStatusIsCachedToAvoidRepeatedTwitchApiCalls(): void
    {
        $client = new FakeTwitchApiClient([12, 99]);
        $checker = new TwitchStatusChecker($client, new ArrayAdapter());

        self::assertSame(12, $checker->check()->viewerCount);
        self::assertSame(12, $checker->check()->viewerCount);
        self::assertSame(1, $client->calls);
    }
}

/**
 * @internal
 */
final class FakeTwitchApiClient implements TwitchApiClientInterface
{
    public int $calls = 0;

    /**
     * @param list<int|null> $viewerCounts
     */
    public function __construct(private array $viewerCounts)
    {
    }

    public function fetchViewerCount(): ?int
    {
        ++$this->calls;

        return array_shift($this->viewerCounts);
    }

    public function fetchLiveLogins(array $logins): array
    {
        return [];
    }

    public function fetchAvatars(array $logins): array
    {
        return [];
    }
}
