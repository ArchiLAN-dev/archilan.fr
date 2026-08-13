<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * The admin user sheet's identity read (story 36.1).
 */
final class AdminUserDetailTest extends FunctionalTestCase
{
    public function testAdminReadsAUserSheet(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $target = $this->createUser('target@example.org', ['ROLE_USER', 'ROLE_MEMBER'], 'Target', slug: 'target');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s', $target->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame($target->getId(), $data['id']);
        self::assertSame('target@example.org', $data['email']);
        self::assertSame('target', $data['slug']);
        self::assertSame('member', $data['role']);
        self::assertSame('active', $data['status']);
        self::assertTrue($data['emailVerified']);
        self::assertIsArray($data['roles']);
        self::assertContains('ROLE_MEMBER', $data['roles']);
    }

    public function testAnAdminAccountReadsAsAdmin(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $other = $this->createUser('other@example.org', ['ROLE_USER', 'ROLE_MEMBER', 'ROLE_ADMIN'], 'Other');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s', $other->getId()));

        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        // Admin wins over member on the primary-role ladder, as everywhere else.
        self::assertSame('admin', $data['role']);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/users/nonexistentid000000000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminIsForbidden(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s', $target->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsUnauthorized(): void
    {
        $target = $this->createUser('target@example.org', ['ROLE_USER'], 'Target');

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/users/%s', $target->getId()));

        self::assertResponseStatusCodeSame(401);
    }
}
