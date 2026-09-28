<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Handler\ExtendDiscordTimeoutsHandler;
use App\Community\Application\Message\ExtendDiscordTimeoutsMessage;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Port\SuspendedMember;
use App\Tests\Unit\CatalogSync\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Story 39.6: Discord caps a timeout at 28 days; every day, each member still suspended on the site is timed
 * out again until the end of the suspension, or for 28 days at most.
 */
final class ExtendDiscordTimeoutsHandlerTest extends TestCase
{
    private RecordingDiscordServer $server;
    private RecordingLogger $logger;
    /** @var list<SuspendedMember> */
    private array $suspended = [];

    protected function setUp(): void
    {
        $this->server = new RecordingDiscordServer();
        $this->logger = new RecordingLogger();
    }

    public function testEachSuspendedMemberIsTimedOutAgain(): void
    {
        $this->suspended = [
            new SuspendedMember('user-1', 'discord-1', '2027-01-01T00:00:00+00:00', 'Récidive'),
            new SuspendedMember('user-2', 'discord-2', '2026-10-01T00:00:00+00:00', 'Comportement'),
            new SuspendedMember('user-3', null, '2027-01-01T00:00:00+00:00', 'Spam'),
        ];

        $this->extend();

        self::assertSame([
            ['discordUserId' => 'discord-1', 'until' => '2026-10-26T03:59:00+00:00', 'reason' => 'Récidive'],
            ['discordUserId' => 'discord-2', 'until' => '2026-10-01T00:00:00+00:00', 'reason' => 'Comportement'],
        ], $this->server->timeouts, 'capped at 28 days; an unlinked account has nothing to extend');
    }

    public function testAMemberInErrorDoesNotStopTheOthers(): void
    {
        $this->suspended = [
            new SuspendedMember('user-1', 'discord-1', '2026-10-01T00:00:00+00:00', 'A'),
            new SuspendedMember('user-2', 'discord-2', '2026-10-01T00:00:00+00:00', 'B'),
        ];
        $this->server->failingMembers = ['discord-1'];

        $this->extend();

        self::assertCount(1, $this->server->timeouts);
        self::assertSame('discord-2', $this->server->timeouts[0]['discordUserId']);
        self::assertContains(['level' => 'warning', 'message' => 'moderation_server.timeout_not_extended'], $this->logger->logs);
    }

    public function testNoBotNothingToDo(): void
    {
        $this->server = new RecordingDiscordServer(configured: false);
        $this->suspended = [new SuspendedMember('user-1', 'discord-1', '2026-10-01T00:00:00+00:00', 'A')];

        $this->extend();

        self::assertSame([], $this->server->timeouts);
    }

    private function extend(): void
    {
        $gateway = self::createStub(MemberModerationGatewayInterface::class);
        $gateway->method('currentlySuspended')->willReturn($this->suspended);

        new ExtendDiscordTimeoutsHandler($gateway, $this->server, new MockClock('2026-09-28 04:00:00'), $this->logger)(new ExtendDiscordTimeoutsMessage());
    }
}
