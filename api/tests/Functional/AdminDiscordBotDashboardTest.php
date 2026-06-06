<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Domain\User;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class AdminDiscordBotDashboardTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->asyncTransport()->reset();
    }

    public function testAnonymousGets401OnStatus(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/discord-bot/status');

        self::assertResponseStatusCodeSame(401);
    }

    public function testStandardUserGets403OnUsers(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/admin/discord-bot/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminGetsStatusShape(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/discord-bot/status');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertArrayHasKey('botOnline', $data);
        self::assertArrayHasKey('guildName', $data);
        self::assertArrayHasKey('memberCount', $data);
        self::assertArrayHasKey('managedRoleIds', $data);
        self::assertArrayHasKey('inviteUrl', $data);
        self::assertIsBool($data['botOnline']);
        self::assertIsArray($data['managedRoleIds']);
        self::assertTrue(null === $data['inviteUrl'] || is_string($data['inviteUrl']));
    }

    public function testUsersEndpointPaginatesLinkedUsersAndSanitizesRoles(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $linkedA = $this->createLinkedUser('b@example.org', 'discord-b', ['ROLE_USER', 'ROLE_MEMBER']);
        $linkedB = $this->createLinkedUser('a@example.org', 'discord-a', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->createUser('unlinked@example.org');
        $this->overwriteRolesJson($linkedB->getId(), '{"bad":"ROLE_USER"}');
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/discord-bot/users?page=1&limit=1');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);
        self::assertArrayHasKey('meta', $response);
        $data = $this->responseData($response);
        $meta = $this->responseMeta($response);
        $firstRow = $this->responseRow($data, 0);
        self::assertSame(2, $meta['total']);
        self::assertSame(1, $meta['page']);
        self::assertSame(1, $meta['limit']);
        self::assertCount(1, $data);
        self::assertSame($linkedB->getId(), $firstRow['id']);
        self::assertSame([], $firstRow['roles']);

        $this->client->jsonRequest('GET', '/api/v1/admin/discord-bot/users?page=2&limit=1');

        self::assertResponseStatusCodeSame(200);
        $response = $this->decodedJsonResponse();
        $data = $this->responseData($response);
        $meta = $this->responseMeta($response);
        $firstRow = $this->responseRow($data, 0);
        self::assertSame(2, $meta['page']);
        self::assertSame($linkedA->getId(), $firstRow['id']);
        self::assertSame(['ROLE_USER', 'ROLE_MEMBER'], $firstRow['roles']);
    }

    public function testResyncQueuesLinkedUsersOnly(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $linkedA = $this->createLinkedUser('a@example.org', 'discord-a', ['ROLE_USER']);
        $linkedB = $this->createLinkedUser('b@example.org', 'discord-b', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->createUser('unlinked@example.org');
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/discord-bot/resync');

        self::assertResponseStatusCodeSame(202);
        $response = $this->decodedJsonResponse();
        self::assertSame(['queued' => 2], $response['data']);

        $messages = array_map(
            static fn ($envelope) => $envelope->getMessage(),
            iterator_to_array($this->asyncTransport()->get()),
        );
        $syncMessages = array_values(array_filter(
            $messages,
            static fn ($message) => $message instanceof SyncDiscordRoleMessage,
        ));
        self::assertCount(2, $syncMessages);
        self::assertContainsEquals(
            new SyncDiscordRoleMessage($linkedA->getId(), 'discord-a', ['ROLE_USER']),
            $syncMessages,
        );
        self::assertContainsEquals(
            new SyncDiscordRoleMessage($linkedB->getId(), 'discord-b', ['ROLE_USER', 'ROLE_ADMIN']),
            $syncMessages,
        );
    }

    /**
     * @param list<string> $roles
     */
    private function createLinkedUser(string $email, string $discordId, array $roles): User
    {
        $user = $this->createUser($email, $roles);
        $user->linkDiscord($discordId, $email, new \DateTimeImmutable('2026-05-16T10:00:00+00:00'));
        $this->entityManager->flush();

        return $user;
    }

    private function overwriteRolesJson(string $userId, string $rolesJson): void
    {
        $connection = $this->entityManager->getConnection();
        $table = $connection->quoteSingleIdentifier($this->entityManager->getClassMetadata(User::class)->getTableName());
        $connection->executeStatement(
            sprintf('UPDATE %s SET roles = :roles WHERE id = :id', $table),
            ['roles' => $rolesJson, 'id' => $userId],
        );
        $this->entityManager->clear();
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @param array<mixed> $response
     *
     * @return list<array<mixed>>
     */
    private function responseData(array $response): array
    {
        $data = $response['data'] ?? null;
        self::assertIsArray($data);
        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<mixed> $response
     *
     * @return array{page: int, limit: int, total: int}
     */
    private function responseMeta(array $response): array
    {
        $meta = $response['meta'] ?? null;
        self::assertIsArray($meta);
        self::assertIsInt($meta['page'] ?? null);
        self::assertIsInt($meta['limit'] ?? null);
        self::assertIsInt($meta['total'] ?? null);

        return ['page' => $meta['page'], 'limit' => $meta['limit'], 'total' => $meta['total']];
    }

    /**
     * @param list<array<mixed>> $data
     *
     * @return array{id: string, roles: list<string>}
     */
    private function responseRow(array $data, int $index): array
    {
        $row = $data[$index] ?? null;
        self::assertIsArray($row);
        self::assertIsString($row['id'] ?? null);
        self::assertIsArray($row['roles'] ?? null);
        $roles = [];
        foreach ($row['roles'] as $role) {
            self::assertIsString($role);
            $roles[] = $role;
        }

        return ['id' => $row['id'], 'roles' => $roles];
    }
}
