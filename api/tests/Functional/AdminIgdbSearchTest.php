<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Infrastructure\StubIgdbHttpClient;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminIgdbSearchTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        StubIgdbHttpClient::reset();

        parent::setUp();

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
}
