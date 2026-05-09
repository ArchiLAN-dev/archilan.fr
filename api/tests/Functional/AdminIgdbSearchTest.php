<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Infrastructure\StubIgdbHttpClient;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminIgdbSearchTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;

    protected function setUp(): void
    {
        StubIgdbHttpClient::reset();

        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

        $metadata = [$this->entityManager->getClassMetadata(User::class)];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousCannotSearchIgdb(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/igdb/search?q=hollow');

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('unauthenticated', $error['code']);
    }

    public function testNonAdminCannotSearchIgdb(): void
    {
        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/admin/igdb/search?q=hollow');

        self::assertResponseStatusCodeSame(403);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('forbidden', $error['code']);
    }

    public function testBlankQueryReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/igdb/search?q=');

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('igdb_query_required', $error['code']);
    }

    public function testMissingQueryParamReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/igdb/search');

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('igdb_query_required', $error['code']);
    }

    public function testValidQueryReturnsResults(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/igdb/search?q=hollow');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);

        $game = $data[0];
        self::assertIsArray($game);
        self::assertSame(1234, $game['igdbId']);
        self::assertSame('Hollow Knight', $game['name']);
        self::assertSame('hollow-knight', $game['slug']);
        self::assertIsString($game['summary']);
        self::assertIsString($game['coverUrl']);
    }

    public function testIgdbAuthFailureReturns502(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        StubIgdbHttpClient::$authFails = true;

        $this->client->jsonRequest('GET', '/api/v1/admin/igdb/search?q=hollow');

        self::assertResponseStatusCodeSame(502);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('igdb_auth_failed', $error['code']);
    }

    public function testIgdbSearchFailureReturns502(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        StubIgdbHttpClient::$searchFails = true;

        $this->client->jsonRequest('GET', '/api/v1/admin/igdb/search?q=hollow');

        self::assertResponseStatusCodeSame(502);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        self::assertSame('igdb_search_failed', $error['code']);
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-05-03T10:00:00+00:00');
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

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
