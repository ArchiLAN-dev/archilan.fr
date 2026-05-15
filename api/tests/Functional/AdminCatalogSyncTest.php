<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\GameSelection\Domain\GameCatalogSync;
use App\GameSelection\Infrastructure\StubIgdbHttpClient;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AdminCatalogSyncTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
            $this->entityManager->getClassMetadata(GameCatalogSync::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        StubIgdbHttpClient::reset();
    }

    public function testIgdbPreviewRequiresAdmin(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync/igdb-preview?name=hollow');
        self::assertResponseStatusCodeSame(401);

        $lambda = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync/igdb-preview?name=hollow');
        self::assertResponseStatusCodeSame(403);
    }

    public function testIgdbPreviewRequiresNameParam(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync/igdb-preview');
        self::assertResponseStatusCodeSame(422);

        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('igdb_name_required', $error['code']);
    }

    public function testIgdbPreviewReturnsCandidatesFromStub(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync/igdb-preview?name=hollow');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data); // StubIgdbHttpClient returns 1 result by default

        $candidate = $data[0];
        self::assertIsArray($candidate);
        self::assertSame(1234, $candidate['igdbId']);
        self::assertSame('Hollow Knight', $candidate['name']);
        self::assertArrayHasKey('summary', $candidate);
        self::assertArrayHasKey('coverUrl', $candidate);
        self::assertArrayNotHasKey('coverImageAlt', $candidate); // AC5: not returned by API
    }

    public function testIgdbPreviewReturnsEmptyArrayWhenStubConfiguredToFail(): void
    {
        StubIgdbHttpClient::$authFails = true;

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync/igdb-preview?name=hollow');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        self::assertSame([], $response['data']); // graceful no-op
    }

    public function testCheckUpdatesRequiresAdmin(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/catalog-sync/check-updates');
        self::assertResponseStatusCodeSame(401);

        $lambda = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('POST', '/api/v1/admin/catalog-sync/check-updates');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCheckUpdatesReturnsZeroCheckedWhenNoTrackedGames(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/catalog-sync/check-updates');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(0, $data['checked']);
        self::assertFalse($data['rateLimitHit']);
    }

    public function testCheckUpdatesChecksTrackedGameAndPersistsLatestVersion(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/nicholasb/hollow-knight');
        $this->entityManager->flush();

        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse(
                (string) json_encode([
                    'tag_name' => 'v1.2.0',
                    'published_at' => '2026-01-01T00:00:00Z',
                    'html_url' => 'https://github.com/nicholasb/hollow-knight/releases/tag/v1.2.0',
                    'assets' => [],
                ]),
                ['response_headers' => ['x-ratelimit-remaining' => ['50']]],
            ),
        ]);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/catalog-sync/check-updates');
        self::assertResponseIsSuccessful();

        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(1, $data['checked']);
        self::assertFalse($data['rateLimitHit']);

        $this->entityManager->refresh($game);
        self::assertSame('1.2.0', $game->getApworldLatestVersion());
        self::assertNotNull($game->getApworldCheckedAt());
    }
}
