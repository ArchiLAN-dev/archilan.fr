<?php

declare(strict_types=1);

namespace App\Streaming\Application;

use App\Streaming\Domain\StreamStatus;
use App\Streaming\Infrastructure\TwitchApiClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class TwitchStatusChecker
{
    public function __construct(
        private readonly TwitchApiClientInterface $client,
        private readonly CacheInterface $cache,
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
