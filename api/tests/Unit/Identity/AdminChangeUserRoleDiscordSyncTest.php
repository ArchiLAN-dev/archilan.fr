<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\AdminChangeUserRole;
use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
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
        $em = $this->makeEm($admin, $target, $events);

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

        $result = $this->makeService($em, $bus)->change($admin, $target->getId(), 'member', true);

        self::assertSame([], $result['errors']);
        self::assertSame(['flush', 'dispatch'], $events);
    }

    public function testDemoteDispatchesSyncMessageWithPostChangeUserRole(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeTarget(withDiscord: true, roles: ['ROLE_USER', 'ROLE_MEMBER']);
        $events = [];
        $em = $this->makeEm($admin, $target, $events);

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

        $result = $this->makeService($em, $bus)->change($admin, $target->getId(), 'user', true);

        self::assertSame([], $result['errors']);
        self::assertSame(['flush', 'dispatch'], $events);
    }

    public function testChangeDoesNotDispatchWhenTargetHasNoDiscordLinked(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeTarget(withDiscord: false);
        $events = [];
        $em = $this->makeEm($admin, $target, $events);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->makeService($em, $bus)->change($admin, $target->getId(), 'member', true);

        self::assertSame(['flush'], $events);
    }

    public function testNoOpRoleChangeDoesNotFlushOrDispatch(): void
    {
        $admin = $this->makeAdmin();
        $target = $this->makeTarget(withDiscord: true, roles: ['ROLE_USER', 'ROLE_MEMBER']);
        $events = [];
        $em = $this->makeEm($admin, $target, $events);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $result = $this->makeService($em, $bus)->change($admin, $target->getId(), 'member', true);

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
        $em = $this->makeEm($admin, $target, $events);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('transport down'));

        $result = $this->makeService($em, $bus)->change($admin, $target->getId(), 'member', true);

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
     */
    private function makeEm(User $admin, User $target, array &$events): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static function (string $class, string $id) use ($admin, $target): ?User {
                if ($id === $target->getId()) {
                    return $target;
                }
                if ($id === $admin->getId()) {
                    return $admin;
                }

                return null;
            }
        );
        $em->method('persist');
        $em->method('flush')->willReturnCallback(static function () use (&$events): void {
            $events[] = 'flush';
        });

        return $em;
    }

    private function makeService(EntityManagerInterface $em, MessageBusInterface $bus): AdminChangeUserRole
    {
        return new AdminChangeUserRole($em, new NullLogger(), $bus);
    }
}
