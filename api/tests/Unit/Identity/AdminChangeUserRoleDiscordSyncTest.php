<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\AdminChangeUserRole;
use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Domain\RoleChangeAudit;
use App\Identity\Domain\RoleChangeAuditRepositoryInterface;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AdminChangeUserRoleDiscordSyncTest extends TestCase
{
    public function testPromoteDispatchesSyncMessageAfterFlushWhenTargetHasDiscordLinked(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeTarget(withDiscord: true);
        $events = [];
        [$userRepo, $auditRepo] = $this->makeRepositories($target, $events);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (object $message): bool {
                return $message instanceof SyncDiscordRoleMessage
                    && 'discord-snowflake-123' === $message->discordUserId
                    && false === $message->removeAll
                    && \in_array('ROLE_MEMBER', $message->archilanRoles, true);
            }))
            ->willReturnCallback(static function (object $message) use (&$events): Envelope {
                $events[] = 'dispatch';

                return new Envelope($message);
            });

        $result = $this->makeService($userRepo, $auditRepo, $bus)->change($admin, $target->getId(), 'member', true);

        self::assertSame([], $result['errors']);
        self::assertSame(['flush', 'dispatch'], $events);
    }

    public function testDemoteDispatchesSyncMessageWithPostChangeUserRole(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeTarget(withDiscord: true, roles: ['ROLE_USER', 'ROLE_MEMBER']);
        $events = [];
        [$userRepo, $auditRepo] = $this->makeRepositories($target, $events);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (object $message): bool {
                return $message instanceof SyncDiscordRoleMessage
                    && 'discord-snowflake-123' === $message->discordUserId
                    && false === $message->removeAll
                    && ['ROLE_USER'] === $message->archilanRoles;
            }))
            ->willReturnCallback(static function (object $message) use (&$events): Envelope {
                $events[] = 'dispatch';

                return new Envelope($message);
            });

        $result = $this->makeService($userRepo, $auditRepo, $bus)->change($admin, $target->getId(), 'user', true);

        self::assertSame([], $result['errors']);
        self::assertSame(['flush', 'dispatch'], $events);
    }

    public function testChangeDoesNotDispatchWhenTargetHasNoDiscordLinked(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeTarget(withDiscord: false);
        $events = [];
        [$userRepo, $auditRepo] = $this->makeRepositories($target, $events);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->makeService($userRepo, $auditRepo, $bus)->change($admin, $target->getId(), 'member', true);

        self::assertSame(['flush'], $events);
    }

    public function testNoOpRoleChangeDoesNotFlushOrDispatch(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeTarget(withDiscord: true, roles: ['ROLE_USER', 'ROLE_MEMBER']);
        $events = [];
        [$userRepo, $auditRepo] = $this->makeRepositories($target, $events);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $result = $this->makeService($userRepo, $auditRepo, $bus)->change($admin, $target->getId(), 'member', true);

        self::assertSame([], $result['errors']);
        $userPayload = $result['user'] ?? null;
        self::assertIsArray($userPayload);
        self::assertSame('member', $userPayload['role']);
        self::assertSame([], $events);
    }

    public function testDispatchFailureAfterFlushDoesNotFailRoleChange(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeTarget(withDiscord: true);
        $events = [];
        [$userRepo, $auditRepo] = $this->makeRepositories($target, $events);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('transport down'));

        $result = $this->makeService($userRepo, $auditRepo, $bus)->change($admin, $target->getId(), 'member', true);

        self::assertSame([], $result['errors']);
        $userPayload = $result['user'] ?? null;
        self::assertIsArray($userPayload);
        self::assertSame('member', $userPayload['role']);
        self::assertSame(['flush'], $events);
    }

    /**
     * @param list<string> $roles
     */
    private function makeTarget(bool $withDiscord = false, array $roles = ['ROLE_USER']): User
    {
        $user = new User(
            'target-id',
            'target@example.com',
            'target@example.com',
            'Target',
            'hash',
            $roles,
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );

        if ($withDiscord) {
            $user->linkDiscord('discord-snowflake-123', 'targetuser#0001', new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        }

        return $user;
    }

    private function makeAdmin(): User
    {
        return new User(
            'admin-id',
            'admin@example.com',
            'admin@example.com',
            'Admin',
            'hash',
            ['ROLE_USER', 'ROLE_ADMIN'],
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    /**
     * @param list<string> $events
     *
     * @return array{0: UserRepositoryInterface, 1: RoleChangeAuditRepositoryInterface}
     */
    private function makeRepositories(User $target, array &$events): array
    {
        $userRepo = $this->createStub(UserRepositoryInterface::class);
        $userRepo->method('findById')->willReturnCallback(
            static fn (string $id): ?User => $id === $target->getId() ? $target : null,
        );

        $auditRepo = $this->createStub(RoleChangeAuditRepositoryInterface::class);
        $auditRepo->method('saveAuditAndFlushUser')->willReturnCallback(
            static function (RoleChangeAudit $audit) use (&$events): void {
                $events[] = 'flush';
            },
        );

        return [$userRepo, $auditRepo];
    }

    private function makeService(UserRepositoryInterface $userRepo, RoleChangeAuditRepositoryInterface $auditRepo, MessageBusInterface $bus): AdminChangeUserRole
    {
        return new AdminChangeUserRole($userRepo, $auditRepo, new NullLogger(), $bus);
    }
}
