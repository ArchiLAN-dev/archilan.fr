<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DiscordOAuthClient implements DiscordOAuthClientInterface
{
    private const AUTH_URL = 'https://discord.com/api/oauth2/authorize';
    private const TOKEN_URL = 'https://discord.com/api/oauth2/token';
    private const USER_URL = 'https://discord.com/api/users/@me';

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function buildAuthorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'identify email',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->httpClient->request('POST', self::TOKEN_URL, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => http_build_query([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ]),
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray();

        return $data;
    }

    public function fetchUser(string $accessToken): array
    {
        $response = $this->httpClient->request('GET', self::USER_URL, [
            'headers' => ['Authorization' => 'Bearer '.$accessToken],
        ]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray();

        return $data;
    }
}
