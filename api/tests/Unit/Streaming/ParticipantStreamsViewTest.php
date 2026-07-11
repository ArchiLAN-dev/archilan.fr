<?php

declare(strict_types=1);

namespace App\Tests\Unit\Streaming;

use App\Streaming\Application\Port\TwitchApiClientInterface;
use App\Streaming\Application\Query\ParticipantStreamsView;
use App\Streaming\Application\Query\ParticipantTwitchLinksQueryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ParticipantStreamsViewTest extends TestCase
{
    public function testOutageIsCachedBrieflyAsNobodyLive(): void
    {
        $liveItem = $this->createMock(ItemInterface::class);
        $liveItem->expects(self::once())->method('expiresAfter')->with(15);

        $view = $this->makeView(liveResult: null, liveItem: $liveItem);

        $streams = $view->forEvent('event-1');

        self::assertNotNull($streams);
        self::assertCount(1, $streams);
        self::assertFalse($streams[0]['live']);
        self::assertNull($streams[0]['viewerCount']);
    }

    public function testAuthoritativeLiveResultIsCachedForSixtySeconds(): void
    {
        $liveItem = $this->createMock(ItemInterface::class);
        $liveItem->expects(self::once())->method('expiresAfter')->with(60);

        $view = $this->makeView(liveResult: ['streamer' => 42], liveItem: $liveItem);

        $streams = $view->forEvent('event-1');

        self::assertNotNull($streams);
        self::assertCount(1, $streams);
        self::assertTrue($streams[0]['live']);
        self::assertSame(42, $streams[0]['viewerCount']);
    }

    public function testAuthoritativeNobodyLiveIsCachedForSixtySeconds(): void
    {
        $liveItem = $this->createMock(ItemInterface::class);
        $liveItem->expects(self::once())->method('expiresAfter')->with(60);

        $view = $this->makeView(liveResult: [], liveItem: $liveItem);

        $streams = $view->forEvent('event-1');

        self::assertNotNull($streams);
        self::assertCount(1, $streams);
        self::assertFalse($streams[0]['live']);
    }

    /**
     * @param array<string, int>|null $liveResult
     */
    private function makeView(?array $liveResult, ItemInterface $liveItem): ParticipantStreamsView
    {
        $query = $this->createStub(ParticipantTwitchLinksQueryInterface::class);
        $query->method('forEvent')->willReturn([
            [
                'userId' => 'user-1',
                'slug' => 'streamer',
                'displayName' => 'Streamer',
                'socialLinks' => [['label' => 'Twitch', 'url' => 'https://twitch.tv/streamer']],
            ],
        ]);

        $client = new readonly class($liveResult) implements TwitchApiClientInterface {
            /** @param array<string, int>|null $liveResult */
            public function __construct(private ?array $liveResult)
            {
            }

            public function fetchViewerCount(): ?int
            {
                return null;
            }

            public function fetchLiveLogins(array $logins): ?array
            {
                return $this->liveResult;
            }

            public function fetchAvatars(array $logins): array
            {
                return [];
            }
        };

        $avatarItem = $this->createStub(ItemInterface::class);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willReturnCallback(
            static fn (string $key, callable $callback): mixed => $callback(str_contains($key, '.live.') ? $liveItem : $avatarItem),
        );

        return new ParticipantStreamsView($query, $client, $cache);
    }
}
