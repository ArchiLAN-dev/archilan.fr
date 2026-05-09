<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class AdminUserDirectoryTest extends WebTestCase
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

        $metadata = [$this->entityManager->getClassMetadata(User::class)];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testUnauthenticatedUserDirectoryAccessIsRejected(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/users');

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('unauthenticated', $response['error']['code']);
    }

    public function testLambdaUserDirectoryAccessIsForbidden(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER'], 'Lambda');
        $this->loginAs($lambda);

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
        $this->createUser('lambda@example.org', ['ROLE_USER'], 'Lambda');
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
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles, ?string $displayName): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            $displayName,
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
