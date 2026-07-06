<?php

declare(strict_types=1);

namespace App\Streaming\Application\Query;

use App\Streaming\Application\Port\TwitchApiClientInterface;
use App\Streaming\Domain\ValueObject\StreamStatus;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final readonly class TwitchStatusChecker
{
    public function __construct(
        private TwitchApiClientInterface $client,
        private CacheInterface $cache,
    ) {
    }

    public function check(): StreamStatus
    {
        return $this->cache->get('streaming.twitch_status', function (ItemInterface $item): StreamStatus {
            $item->expiresAfter(60);

            $viewerCount = $this->client->fetchViewerCount();

            return null !== $viewerCount
                ? StreamStatus::live($viewerCount)
                : StreamStatus::offline();
        });
    }
}
