<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\ArchipelagoGame;
use App\GameSelection\Infrastructure\StubIgdbHttpClient;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AdminCatalogSyncTest extends WebTestCase
{
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

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertSame('igdb_name_required', $response['error']['code']);
    }

    public function testIgdbPreviewReturnsCandidatesFromStub(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/catalog-sync/igdb-preview?name=hollow');
        self::assertResponseIsSuccessful();

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertCount(1, $response['data']); // StubIgdbHttpClient returns 1 result by default

        $candidate = $response['data'][0];
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

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
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

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertSame(0, $response['data']['checked']);
        self::assertFalse($response['data']['rateLimitHit']);
    }

    public function testCheckUpdatesChecksTrackedGameAndPersistsLatestVersion(): void
    {
        $game = ArchipelagoGame::create(
            'Hollow Knight',
            'hollow-knight',
            'A challenging 2D action adventure.',
            null,
            'Hollow Knight cover',
            '',
            ArchipelagoGame::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/nicholasb/hollow-knight');
        $this->entityManager->persist($game);
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

        $response = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertSame(1, $response['data']['checked']);
        self::assertFalse($response['data']['rateLimitHit']);

        $this->entityManager->refresh($game);
        self::assertSame('1.2.0', $game->getApworldLatestVersion());
        self::assertNotNull($game->getApworldCheckedAt());
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
