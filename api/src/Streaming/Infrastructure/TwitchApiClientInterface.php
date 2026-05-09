<?php

declare(strict_types=1);

namespace App\Streaming\Infrastructure;

interface TwitchApiClientInterface
{
    /**
     * Returns the live viewer count, or null when the channel is offline or the API is unavailable.
     */
    public function fetchViewerCount(): ?int;
}
