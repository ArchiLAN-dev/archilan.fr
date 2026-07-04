<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface DiscordOAuthClientInterface
{
    public function buildAuthorizationUrl(string $redirectUri, string $state): string;

    /** @return array<string, mixed> */
    public function exchangeCode(string $code, string $redirectUri): array;

    /** @return array<string, mixed> */
    public function fetchUser(string $accessToken): array;
}
