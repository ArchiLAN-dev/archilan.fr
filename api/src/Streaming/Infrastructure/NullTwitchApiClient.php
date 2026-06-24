<?php

declare(strict_types=1);

namespace App\Streaming\Infrastructure;

final class NullTwitchApiClient implements TwitchApiClientInterface
{
    public function fetchViewerCount(): ?int
    {
        return null;
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
