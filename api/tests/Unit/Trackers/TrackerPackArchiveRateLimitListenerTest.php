<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Trackers\Infrastructure\Http\TrackerPackArchiveRateLimitListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/** Story 54.13: the public pack zips are counted per address. */
final class TrackerPackArchiveRateLimitListenerTest extends TestCase
{
    public function testAnAddressPastItsQuotaGetsA429(): void
    {
        $listener = new TrackerPackArchiveRateLimitListener(new RateLimiterFactory(
            ['id' => 'tracker_pack_archive', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '10 minutes'],
            new InMemoryStorage(),
        ));

        self::assertNull($this->dispatch($listener, TrackerPackArchiveRateLimitListener::ROUTE, '10.0.0.1'));
        self::assertNull($this->dispatch($listener, TrackerPackArchiveRateLimitListener::ROUTE, '10.0.0.1'));
        self::assertSame(429, $this->dispatch($listener, TrackerPackArchiveRateLimitListener::ROUTE, '10.0.0.1'));

        // Another address, another route: untouched.
        self::assertNull($this->dispatch($listener, TrackerPackArchiveRateLimitListener::ROUTE, '10.0.0.2'));
        self::assertNull($this->dispatch($listener, 'api_tracker_packs', '10.0.0.1'));
    }

    /** @phpstan-impure the listener counts each call */
    private function dispatch(TrackerPackArchiveRateLimitListener $listener, string $route, string $ip): ?int
    {
        $request = Request::create('/api/v1/tracker-pack-versions/v1/archive', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set('_route', $route);
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $listener($event);

        return $event->getResponse()?->getStatusCode();
    }
}
