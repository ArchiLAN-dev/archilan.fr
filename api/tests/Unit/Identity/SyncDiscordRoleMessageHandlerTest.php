<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\DiscordBotClientInterface;
use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Application\Message\SyncDiscordRoleMessageHandler;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\Membership\Application\ActiveMembershipQueryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SyncDiscordRoleMessageHandlerTest extends TestCase
{
    private const GUILD_ID = 'guild-123';
    private const DISCORD_USER_ID = 'discord-456';
    private const USER_ID = 'uuid-789';
    private const ROLE_ADMIN = 'role-admin';
    private const ROLE_MEMBER = 'role-member';

    private function makeHandler(
        DiscordBotClientInterface $client,
        ?User $user = null,
        bool $hasActiveMembership = false,
        string $roleIdAdmin = self::ROLE_ADMIN,
        string $roleIdMember = self::ROLE_MEMBER,
    ): SyncDiscordRoleMessageHandler {
        $userRepo = $this->createStub(UserRepositoryInterface::class);
        $userRepo->method('findById')->willReturn($user);

        $membershipQuery = $this->createStub(ActiveMembershipQueryInterface::class);
        $membershipQuery->method('hasActiveMembership')->willReturn($hasActiveMembership);

        return new SyncDiscordRoleMessageHandler(
            $client,
            $userRepo,
            $membershipQuery,
            new NullLogger(),
            self::GUILD_ID,
            $roleIdAdmin,
            $roleIdMember,
        );
    }

    public function testRemoveAllRemovesBothManagedRolesAndNeverAssigns(): void
    {
        $client = $this->createMock(DiscordBotClientInterface::class);

        $client->expects($this->never())->method('assignRole');
        $client->expects($this->exactly(2))
            ->method('removeRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, $this->anything());

        $handler = $this->makeHandler($client);
        ($handler)(new SyncDiscordRoleMessage(self::USER_ID, self::DISCORD_USER_ID, [], removeAll: true));
    }

    public function testMemberOnlyAssignsMemberAndRemovesAdmin(): void
    {
        $user = $this->makeLinkedUser(['ROLE_MEMBER']);
        $client = $this->createMock(DiscordBotClientInterface::class);

        $client->expects($this->once())
            ->method('assignRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, self::ROLE_MEMBER);

        $client->expects($this->once())
            ->method('removeRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, self::ROLE_ADMIN);

        $handler = $this->makeHandler($client, $user);
        ($handler)(new SyncDiscordRoleMessage(self::USER_ID, self::DISCORD_USER_ID, ['ROLE_MEMBER']));
    }

    public function testActiveMembershipWithoutRoleMemberStillGetsMemberDiscordRole(): void
    {
        $user = $this->makeLinkedUser(['ROLE_USER']);
        $client = $this->createMock(DiscordBotClientInterface::class);

        $client->expects($this->once())
            ->method('assignRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, self::ROLE_MEMBER);

        $client->expects($this->once())
            ->method('removeRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, self::ROLE_ADMIN);

        $handler = $this->makeHandler($client, $user, hasActiveMembership: true);
        ($handler)(new SyncDiscordRoleMessage(self::USER_ID, self::DISCORD_USER_ID, ['ROLE_USER']));
    }

    public function testAdminOnlyAssignsAdminAndRemovesMember(): void
    {
        $user = $this->makeLinkedUser(['ROLE_ADMIN']);
        $client = $this->createMock(DiscordBotClientInterface::class);

        $client->expects($this->once())
            ->method('assignRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, self::ROLE_ADMIN);

        $client->expects($this->once())
            ->method('removeRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, self::ROLE_MEMBER);

        $handler = $this->makeHandler($client, $user);
        ($handler)(new SyncDiscordRoleMessage(self::USER_ID, self::DISCORD_USER_ID, ['ROLE_ADMIN']));
    }

    public function testAdminAndMemberAssignsBothDiscordRolesIndependently(): void
    {
        $user = $this->makeLinkedUser(['ROLE_ADMIN', 'ROLE_MEMBER']);
        $client = $this->createMock(DiscordBotClientInterface::class);

        $client->expects($this->exactly(2))
            ->method('assignRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, $this->logicalOr(
                $this->equalTo(self::ROLE_ADMIN),
                $this->equalTo(self::ROLE_MEMBER),
            ));

        $client->expects($this->never())->method('removeRole');

        $handler = $this->makeHandler($client, $user);
        ($handler)(new SyncDiscordRoleMessage(self::USER_ID, self::DISCORD_USER_ID, ['ROLE_ADMIN', 'ROLE_MEMBER']));
    }

    public function testUserWithNoManagedRolesAndNoMembershipRemovesBoth(): void
    {
        $user = $this->makeLinkedUser(['ROLE_USER']);
        $client = $this->createMock(DiscordBotClientInterface::class);

        $client->expects($this->never())->method('assignRole');
        $client->expects($this->exactly(2))
            ->method('removeRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, $this->logicalOr(
                $this->equalTo(self::ROLE_ADMIN),
                $this->equalTo(self::ROLE_MEMBER),
            ));

        $handler = $this->makeHandler($client, $user);
        ($handler)(new SyncDiscordRoleMessage(self::USER_ID, self::DISCORD_USER_ID, ['ROLE_USER']));
    }

    public function testStaleMessageForDifferentCurrentDiscordIdIsSkipped(): void
    {
        $user = $this->makeLinkedUser(['ROLE_MEMBER'], 'new-discord-id');
        $client = $this->createMock(DiscordBotClientInterface::class);

        $client->expects($this->never())->method('assignRole');
        $client->expects($this->never())->method('removeRole');

        $handler = $this->makeHandler($client, $user);
        ($handler)(new SyncDiscordRoleMessage(self::USER_ID, self::DISCORD_USER_ID, ['ROLE_ADMIN']));
    }

    public function testEmptyRoleIdIsSkippedSilently(): void
    {
        $client = $this->createMock(DiscordBotClientInterface::class);

        // roleIdAdmin is empty → only ROLE_MEMBER is processed (removed)
        $client->expects($this->once())
            ->method('removeRole')
            ->with(self::GUILD_ID, self::DISCORD_USER_ID, self::ROLE_MEMBER);

        $handler = $this->makeHandler($client, roleIdAdmin: '');
        ($handler)(new SyncDiscordRoleMessage(self::USER_ID, self::DISCORD_USER_ID, [], removeAll: true));
    }

    /**
     * @param list<string> $roles
     */
    private function makeLinkedUser(array $roles, string $discordId = self::DISCORD_USER_ID): User
    {
        $user = new User(
            self::USER_ID,
            'test@example.com',
            'test@example.com',
            'Test User',
            'hash',
            $roles,
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $user->linkDiscord($discordId, 'discord-user', new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        return $user;
    }
}
