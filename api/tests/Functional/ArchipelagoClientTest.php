<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class ArchipelagoClientTest extends FunctionalTestCase
{
    public function testPublicGetReturnsNullWhenUnset(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/archipelago-client');
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->decodedJsonResponse()['data']);
    }

    public function testAdminUpdatesThenPublicReturnsIt(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-client', [
            'version' => '0.5.1',
            'downloadUrl' => 'https://github.com/ArchipelagoMW/Archipelago/releases',
        ]);
        self::assertResponseStatusCodeSame(200);

        // A second update overwrites the single row (no duplicate).
        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-client', [
            'version' => '0.5.2',
            'downloadUrl' => 'https://github.com/ArchipelagoMW/Archipelago/releases/latest',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/archipelago-client');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('0.5.2', $data['version']);
        self::assertSame('https://github.com/ArchipelagoMW/Archipelago/releases/latest', $data['downloadUrl']);
    }

    public function testAdminUpdateRejectsNonHttpUrl(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-client', [
            'version' => '0.5.1',
            'downloadUrl' => 'javascript:alert(1)',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminUpdateRejectsOverlongVersion(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-client', [
            'version' => str_repeat('9', 60),
            'downloadUrl' => 'https://example.org',
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAdminUpdateRequiresAdmin(): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-client', ['version' => '1', 'downloadUrl' => 'https://x.org']);
        self::assertResponseStatusCodeSame(401);

        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);
        $this->client->jsonRequest('PUT', '/api/v1/admin/archipelago-client', ['version' => '1', 'downloadUrl' => 'https://x.org']);
        self::assertResponseStatusCodeSame(403);
    }
}
