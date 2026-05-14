<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CatalogSyncEndpointTest extends WebTestCase
{
    private const EMPTY_CSV = "Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes\n";

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(ArchipelagoGame::class),
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

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
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
        $game = ArchipelagoGame::create(
            'Hollow Knight',
            'hollow-knight',
            'A platformer.',
            null,
            'Cover',
            '',
            ArchipelagoGame::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/nicholasb/hollow-knight');
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $this->configureEmptySheetMock();

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertCount(1, $response['apworldUpdates']);

        $update = $response['apworldUpdates'][0];
        self::assertSame($game->getId(), $update['gameId']);
        self::assertSame('Hollow Knight', $update['gameName']);
        self::assertNull($update['deployedVersion']);
        self::assertNull($update['latestVersion']);
        self::assertNull($update['releaseUrl']);
        self::assertNull($update['publishedAt']);
        self::assertSame(ArchipelagoGame::UPDATE_STATUS_UNKNOWN, $update['updateStatus']);
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

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('sheet_unavailable', $response['error']['code']);
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
        $game = ArchipelagoGame::create(
            'Bundled Game',
            'bundled-game',
            'Bundled.',
            null,
            'Cover',
            '',
            ArchipelagoGame::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        // No sourceUrl set → not_tracked
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $this->configureEmptySheetMock();

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync');
        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(ArchipelagoGame::UPDATE_STATUS_NOT_TRACKED, $response['apworldUpdates'][0]['updateStatus']);
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

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertCount(1, $response['newGames']);
        $newGame = $response['newGames'][0];
        self::assertArrayHasKey('adultContent', $newGame);
        self::assertTrue($newGame['adultContent']);
    }

    public function testCatalogSyncStabilityChangedIncludesAvailabilityLockedField(): void
    {
        $game = ArchipelagoGame::create(
            'Hollow Knight',
            'hollow-knight',
            'A platformer.',
            null,
            'Cover',
            '',
            ArchipelagoGame::AVAILABILITY_EXPERIMENTAL,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $game->updateCatalogueMetadata(catalogSheetName: 'Hollow Knight');
        $this->entityManager->persist($game);
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

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertCount(1, $response['stabilityChanged']);
        $change = $response['stabilityChanged'][0];
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

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertCount(1, $response['newGames']);
        self::assertSame('Hollow Knight', $response['newGames'][0]['name']);
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

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            $roles,
            $now,
            $now,
            $now,
        );

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }
}
