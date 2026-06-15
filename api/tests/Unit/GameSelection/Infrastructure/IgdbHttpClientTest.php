<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection\Infrastructure;

use App\GameSelection\Infrastructure\IgdbAuthException;
use App\GameSelection\Infrastructure\IgdbHttpClient;
use App\GameSelection\Infrastructure\IgdbSearchException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IgdbHttpClientTest extends TestCase
{
    public function testSearchGamesMapsCoverUrlAndFields(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse(json_encode([
                [
                    'id' => 1234,
                    'name' => 'Hollow Knight',
                    'slug' => 'hollow-knight',
                    'summary' => 'A challenging 2D action adventure.',
                    'cover' => ['image_id' => 'co1rgi'],
                ],
                [
                    'id' => 5678,
                    'name' => 'Celeste',
                    'slug' => 'celeste',
                ],
            ]) ?: ''),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');
        $results = $client->searchGames('knight');

        self::assertCount(2, $results);

        self::assertSame(1234, $results[0]['igdbId']);
        self::assertSame('Hollow Knight', $results[0]['name']);
        self::assertSame('hollow-knight', $results[0]['slug']);
        self::assertSame('A challenging 2D action adventure.', $results[0]['summary']);
        self::assertSame('https://images.igdb.com/igdb/image/upload/t_cover_big/co1rgi.jpg', $results[0]['coverUrl']);

        self::assertSame(5678, $results[1]['igdbId']);
        self::assertNull($results[1]['summary']);
        self::assertNull($results[1]['coverUrl']);
    }

    public function testTokenIsCachedAcrossMultipleCalls(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse(json_encode([['id' => 1, 'name' => 'Game', 'slug' => 'game']]) ?: ''),
            new MockResponse(json_encode([['id' => 1, 'name' => 'Game', 'slug' => 'game']]) ?: ''),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        $client->searchGames('test');
        $client->searchGames('test');

        // 1 token request + 2 search requests = 3 total (not 4)
        self::assertSame(3, $http->getRequestsCount());
    }

    public function testAuthFailureThrowsIgdbAuthException(): void
    {
        $http = new MockHttpClient([
            new MockResponse('{"error":"unauthorized"}', ['http_code' => 401]),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        $this->expectException(IgdbAuthException::class);
        $client->searchGames('hollow');
    }

    public function testSearchFailureThrowsIgdbSearchException(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse('Internal Server Error', ['http_code' => 500]),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        $this->expectException(IgdbSearchException::class);
        $client->searchGames('hollow');
    }

    public function testCoverUrlIsNullWhenNoCoverField(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse(json_encode([
                ['id' => 1, 'name' => 'No Cover Game', 'slug' => 'no-cover'],
            ]) ?: ''),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');
        $results = $client->searchGames('no cover');

        self::assertNull($results[0]['coverUrl']);
    }

    public function testFetchSteamAppIdMapsUidToInt(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse(json_encode([
                ['uid' => '367520', 'external_game_source' => 1],
            ]) ?: ''),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        self::assertSame(367520, $client->fetchSteamAppId(1234));
    }

    public function testFetchSteamAppIdReturnsNullWhenNoSteamEntry(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse(json_encode([]) ?: ''),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        self::assertNull($client->fetchSteamAppId(1234));
    }

    public function testFetchSteamAppIdReturnsNullWhenUidNotNumeric(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse(json_encode([
                ['uid' => 'not-a-number', 'category' => 1],
            ]) ?: ''),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        self::assertNull($client->fetchSteamAppId(1234));
    }

    public function testFetchSteamAppIdThrowsOnHttpError(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse('Internal Server Error', ['http_code' => 500]),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        $this->expectException(IgdbSearchException::class);
        $client->fetchSteamAppId(1234);
    }

    public function testFetchPlatformsMapsIdAndName(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse(json_encode([
                ['id' => 1103, 'platforms' => [['id' => 19, 'name' => 'Super Nintendo Entertainment System'], ['id' => 5, 'name' => 'Wii']]],
            ]) ?: ''),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        self::assertSame(
            [['id' => 19, 'name' => 'Super Nintendo Entertainment System'], ['id' => 5, 'name' => 'Wii']],
            $client->fetchPlatforms(1103),
        );
    }

    public function testFetchPlatformsReturnsEmptyWhenNone(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse(json_encode([['id' => 1, 'name' => 'No Platforms']]) ?: ''),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        self::assertSame([], $client->fetchPlatforms(1));
    }

    public function testFetchPlatformsThrowsOnHttpError(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 3600]) ?: ''),
            new MockResponse('Internal Server Error', ['http_code' => 500]),
        ]);

        $client = new IgdbHttpClient($http, new ArrayAdapter(), 'cid', 'csec');

        $this->expectException(IgdbSearchException::class);
        $client->fetchPlatforms(1);
    }

    public function testTokenTtlIsNinetyPercentOfExpiresIn(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['access_token' => 'tok', 'expires_in' => 1000]) ?: ''),
            new MockResponse(json_encode([]) ?: ''),
        ]);

        $cache = new ArrayAdapter();
        $client = new IgdbHttpClient($http, $cache, 'cid', 'csec');

        $client->searchGames('test');

        $item = $cache->getItem('igdb.access_token');
        self::assertTrue($item->isHit());
        // TTL = 900s → expiry is within [now+895, now+905] to account for test runtime
        $expiry = $item->getMetadata()[\Symfony\Component\Cache\CacheItem::METADATA_EXPIRY] ?? null;
        if (is_float($expiry) || is_int($expiry)) {
            $ttl = $expiry - microtime(true);
            self::assertGreaterThan(890.0, $ttl);
            self::assertLessThan(910.0, $ttl);
        }
    }
}
