<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\LinkDiscordToAccount;
use App\Identity\Application\Message\SyncDiscordRoleMessage;
use App\Identity\Application\UnlinkDiscordFromAccount;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\DiscordOAuthClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DiscordLinkSyncDispatchTest extends TestCase
{
    private const USER_ID = 'user-123';

    public function testLinkDispatchesSyncAfterSuccessfulFlush(): void
    {
        $user = $this->makeUser();
        $oauth = $this->createConfiguredOAuthClient('discord-new', 'discord-user');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($user);
        $em->expects($this->once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (object $message): bool => $message instanceof SyncDiscordRoleMessage
                && self::USER_ID === $message->userId
                && 'discord-new' === $message->discordUserId
                && false === $message->removeAll))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $result = $this->makeLinkService($oauth, $em, $bus)->link(self::USER_ID, 'oauth-code');

        self::assertSame(['outcome' => 'linked'], $result);
    }

    public function testRelinkDispatchesRemoveAllForPreviousDiscordIdAndSyncForNewOne(): void
    {
        $user = $this->makeUser();
        $user->linkDiscord('discord-old', 'old-user', new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $oauth = $this->createConfiguredOAuthClient('discord-new', 'new-user');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($user);
        $em->expects($this->once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $call = 0;
        $bus->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$call): Envelope {
                ++$call;

                self::assertInstanceOf(SyncDiscordRoleMessage::class, $message);
                if (1 === $call) {
                    self::assertSame('discord-old', $message->discordUserId);
                    self::assertTrue($message->removeAll);
                } else {
                    self::assertSame('discord-new', $message->discordUserId);
                    self::assertFalse($message->removeAll);
                }

                return new Envelope($message);
            });

        $result = $this->makeLinkService($oauth, $em, $bus)->link(self::USER_ID, 'oauth-code');

        self::assertSame(['outcome' => 'linked'], $result);
    }

    public function testLinkStillReturnsLinkedWhenDiscordSyncDispatchFailsAfterFlush(): void
    {
        $user = $this->makeUser();
        $oauth = $this->createConfiguredOAuthClient('discord-new', 'discord-user');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($user);
        $em->expects($this->once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('transport down'));

        $result = $this->makeLinkService($oauth, $em, $bus)->link(self::USER_ID, 'oauth-code');

        self::assertSame(['outcome' => 'linked'], $result);
    }

    public function testUnlinkDispatchesRemoveAllWithCapturedDiscordIdAfterFlush(): void
    {
        $user = $this->makeUser();
        $user->linkDiscord('discord-old', 'old-user', new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($user);
        $em->expects($this->once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (object $message): bool => $message instanceof SyncDiscordRoleMessage
                && self::USER_ID === $message->userId
                && 'discord-old' === $message->discordUserId
                && true === $message->removeAll))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->makeUnlinkService($em, $bus)->unlink(self::USER_ID);

        self::assertNull($user->getDiscordId());
    }

    public function testUnlinkDoesNotSurfaceDispatchFailureAfterFlush(): void
    {
        $user = $this->makeUser();
        $user->linkDiscord('discord-old', 'old-user', new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($user);
        $em->expects($this->once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('transport down'));

        $this->makeUnlinkService($em, $bus)->unlink(self::USER_ID);

        self::assertNull($user->getDiscordId());
    }

    private function makeUser(): User
    {
        return new User(
            self::USER_ID,
            'test@example.com',
            'test@example.com',
            'Test User',
            'hash',
            ['ROLE_USER'],
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
    }

    private function createConfiguredOAuthClient(string $discordId, string $discordUsername): DiscordOAuthClientInterface
    {
        $oauth = $this->createStub(DiscordOAuthClientInterface::class);
        $oauth->method('exchangeCode')->willReturn(['access_token' => 'access-token']);
        $oauth->method('fetchUser')->willReturn([
            'id' => $discordId,
            'username' => $discordUsername,
            'verified' => true,
        ]);

        return $oauth;
    }

    private function makeLinkService(
        DiscordOAuthClientInterface $oauth,
        EntityManagerInterface $em,
        MessageBusInterface $bus,
    ): LinkDiscordToAccount {
        return new LinkDiscordToAccount($oauth, $em, new NullLogger(), $bus, 'https://app.test/discord/link');
    }

    private function makeUnlinkService(EntityManagerInterface $em, MessageBusInterface $bus): UnlinkDiscordFromAccount
    {
        return new UnlinkDiscordFromAccount($em, new NullLogger(), $bus);
    }
}
