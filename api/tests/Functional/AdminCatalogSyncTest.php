<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
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
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(GameCatalogSync::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testCheckUpdatesRequiresAdmin(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/catalog-sync/check-updates');
        self::assertResponseStatusCodeSame(401);

        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

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
                (string) json_encode([[
                    'tag_name' => 'v1.2.0',
                    'published_at' => '2026-01-01T00:00:00Z',
                    'html_url' => 'https://github.com/nicholasb/hollow-knight/releases/tag/v1.2.0',
                    'assets' => [
                        ['name' => 'hollow-knight.apworld', 'browser_download_url' => 'https://example.com/hk.apworld'],
                    ],
                    'draft' => false,
                    'prerelease' => false,
                ]]),
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
