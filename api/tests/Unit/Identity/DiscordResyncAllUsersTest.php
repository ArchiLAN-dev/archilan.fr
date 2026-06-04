<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Infrastructure\DbalDiscordResyncAllUsers;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DiscordResyncAllUsersTest extends TestCase
{
    public function testDryRunCountsLinkedUsersWithoutDispatching(): void
    {
        $connection = $this->connection();
        $this->insertUser($connection, 'user-a', 'discord-a', ['ROLE_USER']);
        $this->insertUser($connection, 'user-b', null, ['ROLE_USER']);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $count = $this->service($connection, $bus)->run(dryRun: true);

        self::assertSame(1, $count);
    }

    public function testDispatchesOneMessagePerLinkedUser(): void
    {
        $connection = $this->connection();
        $this->insertUser($connection, 'user-a', 'discord-a', ['ROLE_USER']);
        $this->insertUser($connection, 'user-b', 'discord-b', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->insertUser($connection, 'user-c', null, ['ROLE_USER']);
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message, $stamps);
            }
        };

        $count = $this->service($connection, $bus)->run();

        self::assertSame(2, $count);
        self::assertCount(2, $bus->messages);
        self::assertEquals(new SyncDiscordRoleMessage('user-a', 'discord-a', ['ROLE_USER']), $bus->messages[0]);
        self::assertEquals(new SyncDiscordRoleMessage('user-b', 'discord-b', ['ROLE_USER', 'ROLE_ADMIN']), $bus->messages[1]);
    }

    public function testDispatchFailureIsSurfacedInsteadOfReportedAsNoAccounts(): void
    {
        $connection = $this->connection();
        $this->insertUser($connection, 'user-a', 'discord-a', ['ROLE_USER']);
        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('transport down');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to dispatch 1 Discord role sync message.');

        $this->service($connection, $bus)->run();
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE "user" (
                id VARCHAR(32) NOT NULL,
                discord_id VARCHAR(32) DEFAULT NULL,
                roles CLOB NOT NULL
            )
            SQL);

        return $connection;
    }

    /**
     * @param list<string> $roles
     */
    private function insertUser(Connection $connection, string $id, ?string $discordId, array $roles): void
    {
        $connection->insert('user', [
            'id' => $id,
            'discord_id' => $discordId,
            'roles' => json_encode($roles, JSON_THROW_ON_ERROR),
        ]);
    }

    private function service(Connection $connection, MessageBusInterface $bus): DbalDiscordResyncAllUsers
    {
        return new DbalDiscordResyncAllUsers($connection, $bus, new NullLogger());
    }
}
