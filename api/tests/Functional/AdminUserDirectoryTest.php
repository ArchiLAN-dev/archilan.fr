<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class AdminUserDirectoryTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testUnauthenticatedUserDirectoryAccessIsRejected(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('unauthenticated', $response['error']['code']);
    }

    public function testStandardUserDirectoryAccessIsForbidden(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(403);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('forbidden', $response['error']['code']);
    }

    public function testAdminCanListUsersWithoutSensitiveAuthInternals(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->createUser('member@example.org', ['ROLE_USER', 'ROLE_MEMBER'], 'Membre');
        $deleted = $this->createUser('old@example.org', ['ROLE_USER'], 'Ancien');
        $deleted->anonymizeForDeletion(new \DateTimeImmutable('2026-04-25T12:00:00+00:00'));
        $this->entityManager->flush();

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/users');

        self::assertResponseIsSuccessful();
        $users = $this->decodedUserData();
        self::assertCount(3, $users);

        $firstUser = $users[0];
        self::assertArrayHasKey('email', $firstUser);
        self::assertArrayHasKey('displayName', $firstUser);
        self::assertArrayHasKey('role', $firstUser);
        self::assertArrayHasKey('status', $firstUser);
        self::assertArrayNotHasKey('password', $firstUser);
        self::assertArrayNotHasKey('passwordHash', $firstUser);
        self::assertArrayNotHasKey('session', $firstUser);

        $statuses = array_column($users, 'status', 'email');
        self::assertSame('deleted', $statuses[$deleted->getEmail()] ?? null);
    }

    public function testAdminCanSearchUsersByTextQuery(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->createUser('zelda@example.org', ['ROLE_USER'], 'Zelda Runner');
        $this->createUser('mario@example.org', ['ROLE_USER'], 'Plombier');

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/users?q=zelda');

        self::assertResponseIsSuccessful();
        $users = $this->decodedUserData();
        self::assertCount(1, $users);
        self::assertSame('zelda@example.org', $users[0]['email']);
    }

    public function testAdminCanFilterUsersByRole(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->createUser('lambda@example.org', ['ROLE_USER'], 'User');
        $this->createUser('member@example.org', ['ROLE_USER', 'ROLE_MEMBER'], 'Membre');

        $this->loginAs($admin);
        $this->client->jsonRequest('GET', '/api/v1/admin/users?role=member');

        self::assertResponseIsSuccessful();
        $users = $this->decodedUserData();
        self::assertCount(1, $users);
        self::assertSame('member@example.org', $users[0]['email']);
        self::assertSame('member', $users[0]['role']);
    }

    /**
     * @return list<array{id: string, email: string, displayName: string|null, role: string, roles: list<string>, status: string, createdAt: string, updatedAt: string, deletedAt: string|null}>
     */
    private function decodedUserData(): array
    {
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);

        $users = [];
        foreach ($response['data'] as $user) {
            self::assertIsArray($user);
            self::assertIsString($user['id']);
            self::assertIsString($user['email']);
            self::assertTrue(is_string($user['displayName']) || null === $user['displayName']);
            self::assertIsString($user['role']);
            self::assertIsArray($user['roles']);
            $roles = $this->stringList($user['roles']);
            self::assertIsString($user['status']);
            self::assertIsString($user['createdAt']);
            self::assertIsString($user['updatedAt']);
            self::assertTrue(is_string($user['deletedAt']) || null === $user['deletedAt']);

            $users[] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'displayName' => $user['displayName'],
                'role' => $user['role'],
                'roles' => $roles,
                'status' => $user['status'],
                'createdAt' => $user['createdAt'],
                'updatedAt' => $user['updatedAt'],
                'deletedAt' => $user['deletedAt'],
            ];
        }

        return $users;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            self::assertIsString($value);
            $strings[] = $value;
        }

        return $strings;
    }
}
