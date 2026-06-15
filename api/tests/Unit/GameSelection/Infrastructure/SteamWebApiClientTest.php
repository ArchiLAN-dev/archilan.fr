<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection\Infrastructure;

use App\GameSelection\Infrastructure\SteamApiException;
use App\GameSelection\Infrastructure\SteamWebApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SteamWebApiClientTest extends TestCase
{
    public function testResolveVanityUrlReturnsSteamId(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['response' => ['steamid' => '76561197960287930', 'success' => 1]]) ?: ''),
        ]);

        $client = new SteamWebApiClient($http, 'key');

        self::assertSame('76561197960287930', $client->resolveVanityUrl('gaben'));
    }

    public function testResolveVanityUrlReturnsNullWhenNotFound(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['response' => ['success' => 42]]) ?: ''),
        ]);

        $client = new SteamWebApiClient($http, 'key');

        self::assertNull($client->resolveVanityUrl('ghost'));
    }

    public function testFetchOwnedAppIdsMapsPublicLibrary(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['response' => ['game_count' => 2, 'games' => [['appid' => 10], ['appid' => 20]]]]) ?: ''),
        ]);

        $client = new SteamWebApiClient($http, 'key');
        $result = $client->fetchOwnedAppIds('76561197960287930');

        self::assertSame('public', $result['visibility']);
        self::assertSame([10, 20], $result['appIds']);
    }

    public function testFetchOwnedAppIdsMapsPrivateProfileToEmpty(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['response' => []]) ?: ''),
        ]);

        $client = new SteamWebApiClient($http, 'key');
        $result = $client->fetchOwnedAppIds('76561197960287930');

        self::assertSame('private', $result['visibility']);
        self::assertSame([], $result['appIds']);
    }

    public function testHttpErrorThrowsSteamApiException(): void
    {
        $http = new MockHttpClient([
            new MockResponse('Internal Server Error', ['http_code' => 500]),
        ]);

        $client = new SteamWebApiClient($http, 'key');

        $this->expectException(SteamApiException::class);
        $client->fetchOwnedAppIds('76561197960287930');
    }

    public function testEmptyApiKeyShortCircuits(): void
    {
        $http = new MockHttpClient([]);
        $client = new SteamWebApiClient($http, '');

        self::assertNull($client->resolveVanityUrl('gaben'));
        self::assertSame('private', $client->fetchOwnedAppIds('76561197960287930')['visibility']);
        self::assertSame(0, $http->getRequestsCount());
    }
}
