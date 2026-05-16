<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
use App\GameSelection\Domain\IgnoredCatalogEntry;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogSyncEndpointTest extends FunctionalTestCase
{
    private const EMPTY_CSV = "Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes\n";

    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(GameCatalogSync::class),
            $this->entityManager->getClassMetadata(IgnoredCatalogEntry::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testCatalogSyncRequiresAdmin(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseStatusCodeSame(401);

        $lambda = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCatalogSyncReturnsExpectedResponseShape(): void
    {
        $this->configureEmptySheetMock();

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('cachedAt', $response);
        self::assertArrayHasKey('googleApiAvailable', $response);
        self::assertArrayHasKey('githubChecksAvailable', $response);
        self::assertArrayHasKey('newGames', $response);
        self::assertArrayHasKey('stabilityChanged', $response);
        self::assertArrayHasKey('removedFromSheet', $response);
        self::assertArrayHasKey('apworldUpdates', $response);

        self::assertFalse($response['googleApiAvailable']); // GOOGLE_API_KEY is empty in test
        self::assertTrue($response['githubChecksAvailable']); // GITHUB_TOKEN set in test
        self::assertIsArray($response['newGames']);
        self::assertIsArray($response['apworldUpdates']);
    }

    public function testCatalogSyncApworldUpdatesIncludesAllGames(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/nicholasb/hollow-knight');
        $this->entityManager->flush();

        $this->configureEmptySheetMock();

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        $apworldUpdates = $response['apworldUpdates'];
        self::assertIsArray($apworldUpdates);
        self::assertCount(1, $apworldUpdates);

        $update = $apworldUpdates[0];
        self::assertIsArray($update);
        self::assertSame($game->getId(), $update['gameId']);
        self::assertSame('Hollow Knight', $update['gameName']);
        self::assertNull($update['deployedVersion']);
        self::assertNull($update['latestVersion']);
        self::assertNull($update['releaseUrl']);
        self::assertNull($update['publishedAt']);
        self::assertSame(Game::UPDATE_STATUS_UNKNOWN, $update['updateStatus']);
    }

    public function testCatalogSyncReturns503WhenSheetUnreachable(): void
    {
        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse('Service Unavailable', ['http_code' => 503]),
            new MockResponse('Service Unavailable', ['http_code' => 503]),
        ]);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseStatusCodeSame(503);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('sheet_unavailable', $error['code']);
    }

    public function testCatalogSyncForceReturns503WhenSheetUnreachable(): void
    {
        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse('Service Unavailable', ['http_code' => 503]),
            new MockResponse('Service Unavailable', ['http_code' => 503]),
        ]);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync?force=true');
        self::assertResponseStatusCodeSame(503);
    }

    public function testCatalogSyncNotTrackedWhenNoGithubUrl(): void
    {
        $game = $this->createGame('Bundled Game', 'bundled-game');
        // No sourceUrl set → not_tracked

        $this->configureEmptySheetMock();

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        $apworldUpdates = $response['apworldUpdates'];
        self::assertIsArray($apworldUpdates);
        $firstUpdate = $apworldUpdates[0];
        self::assertIsArray($firstUpdate);
        self::assertSame(Game::UPDATE_STATUS_NOT_TRACKED, $firstUpdate['updateStatus']);
    }

    public function testCatalogSyncNewGamesIncludeAdultContentField(): void
    {
        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse("Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes\nHollow Knight,Stable,,Github Releases,Yes,\n"),
            new MockResponse(self::EMPTY_CSV),
        ]);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        $newGames = $response['newGames'];
        self::assertIsArray($newGames);
        self::assertCount(1, $newGames);
        $newGame = $newGames[0];
        self::assertIsArray($newGame);
        self::assertArrayHasKey('adultContent', $newGame);
        self::assertTrue($newGame['adultContent']);
    }

    public function testCatalogSyncStabilityChangedIncludesAvailabilityLockedField(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight', Game::AVAILABILITY_EXPERIMENTAL);
        $game->updateCatalogueMetadata(catalogSheetName: 'Hollow Knight');
        $this->entityManager->flush();

        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse("Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes\nHollow Knight,Stable,,Github Releases,No,\n"),
            new MockResponse(self::EMPTY_CSV),
        ]);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        $stabilityChanged = $response['stabilityChanged'];
        self::assertIsArray($stabilityChanged);
        self::assertCount(1, $stabilityChanged);
        $change = $stabilityChanged[0];
        self::assertIsArray($change);
        self::assertArrayHasKey('availabilityLocked', $change);
        self::assertFalse($change['availabilityLocked']);
    }

    public function testCatalogSyncRowsWithInvalidStabilityAreExcluded(): void
    {
        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse("Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes\nDescription row,,,,,\nHollow Knight,Stable,,Github Releases,No,\nBroken Status,UnknownStatus,,Github Releases,No,\n"),
            new MockResponse(self::EMPTY_CSV),
        ]);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        $newGames = $response['newGames'];
        self::assertIsArray($newGames);
        self::assertCount(1, $newGames);
        $firstGame = $newGames[0];
        self::assertIsArray($firstGame);
        self::assertSame('Hollow Knight', $firstGame['name']);
    }

    private function configureEmptySheetMock(): void
    {
        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse(self::EMPTY_CSV),
            new MockResponse(self::EMPTY_CSV),
        ]);
    }
}
