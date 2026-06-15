<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\GameCatalogSync;
use App\GameSelection\Infrastructure\StubSteamWebApiClient;

final class SteamCouplingEndpointTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        StubSteamWebApiClient::reset();
    }

    protected function tearDown(): void
    {
        StubSteamWebApiClient::reset();
        parent::tearDown();
    }

    public function testCouplingReturnsMatchedGames(): void
    {
        $this->seedGameWithSteamAppId('Hollow Knight', 'hollow-knight', 367520);
        $this->seedGameWithSteamAppId('Celeste', 'celeste', 504230);

        StubSteamWebApiClient::$visibility = 'public';
        StubSteamWebApiClient::$ownedAppIds = [367520, 99999];

        $this->client->jsonRequest('POST', '/api/v1/games/steam-coupling', ['steamProfile' => '76561197960287930']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $meta = $response['meta'];
        $data = $response['data'];
        self::assertIsArray($meta);
        self::assertIsArray($data);
        self::assertSame('ok', $meta['outcome']);
        self::assertSame(2, $data['ownedCount']);
        self::assertSame(1, $data['matchedCount']);
        self::assertIsArray($data['matchedGames']);
        self::assertCount(1, $data['matchedGames']);
        $firstMatch = $data['matchedGames'][0];
        self::assertIsArray($firstMatch);
        self::assertSame('hollow-knight', $firstMatch['slug']);
    }

    public function testPrivateProfileReturnsNoMatches(): void
    {
        $this->seedGameWithSteamAppId('Hollow Knight', 'hollow-knight', 367520);

        StubSteamWebApiClient::$visibility = 'private';

        $this->client->jsonRequest('POST', '/api/v1/games/steam-coupling', ['steamProfile' => '76561197960287930']);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $meta = $response['meta'];
        $data = $response['data'];
        self::assertIsArray($meta);
        self::assertIsArray($data);
        self::assertSame('private_profile', $meta['outcome']);
        self::assertSame(0, $data['matchedCount']);
    }

    public function testEmptyInputReturns422(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/games/steam-coupling', ['steamProfile' => '']);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('steam_invalid_input', $error['code']);
    }

    private function seedGameWithSteamAppId(string $name, string $slug, int $steamAppId): void
    {
        $game = $this->createGame($name, $slug);
        $sync = new GameCatalogSync($game, igdbId: $steamAppId, steamAppId: $steamAppId);
        $this->entityManager->persist($sync);
        $this->entityManager->flush();
    }
}
