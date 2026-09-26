<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Exception\StaffAlertTemporarilyUnavailableException;
use App\GameSelection\Application\Handler\PostApworldIncidentToStaffChannelHandler;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Application\Support\StaffAlertFactory;
use App\GameSelection\Application\Support\StaffAlertLevel;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class PostApworldIncidentToStaffChannelHandlerTest extends TestCase
{
    private InMemoryApworldIncidentRepository $incidents;
    private ApworldIncident $incident;
    private WarningCollectingLogger $logger;

    protected function setUp(): void
    {
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->incident = ApworldIncident::open(
            'incident-1',
            'game-1',
            'hash-1',
            ApworldIncidentType::PreflightFailed,
            'Fill.FillError: boom',
            new \DateTimeImmutable('2026-09-24 10:00:00+00:00'),
        );
        $this->incidents->save($this->incident);
        $this->logger = new WarningCollectingLogger();
    }

    public function testPostsTheOpenedAlert(): void
    {
        $channel = new SpyStaffAlertChannel();

        $this->handler($channel)(new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Opened));

        self::assertCount(1, $channel->posted);
        self::assertSame('Apworld en échec : Crystal Project', $channel->posted[0]->title);
        self::assertSame(StaffAlertLevel::Alert, $channel->posted[0]->level);
    }

    public function testPostsTheAcknowledgedAlertWithTheAdminName(): void
    {
        $this->incident->acknowledge('admin-1', new \DateTimeImmutable('2026-09-24 11:00:00+00:00'));
        $channel = new SpyStaffAlertChannel();

        $this->handler($channel)(new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Acknowledged));

        self::assertSame("Jean s'occupe de Crystal Project", $channel->posted[0]->title);
    }

    public function testPostsTheAutomaticResolution(): void
    {
        $this->incident->resolve(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), null);
        $channel = new SpyStaffAlertChannel();

        $this->handler($channel)(new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Resolved));

        self::assertSame('Crystal Project : incident résolu automatiquement', $channel->posted[0]->title);
    }

    public function testPostsTheResolutionByAnAdminWithTheirName(): void
    {
        $this->incident->resolve(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), 'admin-1');
        $channel = new SpyStaffAlertChannel();

        $this->handler($channel)(new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Resolved));

        self::assertSame('Crystal Project : incident résolu par Jean', $channel->posted[0]->title);
    }

    public function testMissingIncidentPostsNothing(): void
    {
        $channel = new SpyStaffAlertChannel();

        $this->handler($channel)(new PostApworldIncidentToStaffChannelJob('unknown', StaffAlertEvent::Opened));

        self::assertSame([], $channel->posted);
    }

    public function testAClosedEventOnAnIncidentStillActivePostsNothingAndWarns(): void
    {
        $channel = new SpyStaffAlertChannel();

        $this->handler($channel)(new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Resolved));

        self::assertSame([], $channel->posted);
        self::assertCount(1, $this->logger->warnings);
    }

    public function testAPassingFailureIsHandedBackForARetry(): void
    {
        // Story 38.2 review: a Discord rate limit is retried by the transport (3 times), not lost.
        $this->expectException(StaffAlertTemporarilyUnavailableException::class);

        $this->handler(new SpyStaffAlertChannel(failing: true, transient: true))(new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Opened));
    }

    public function testChannelFailureIsLoggedAndSwallowed(): void
    {
        $channel = new SpyStaffAlertChannel(failing: true);

        $this->handler($channel)(new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Opened));

        self::assertSame([], $channel->posted);
        self::assertSame(['apworld_incidents.staff_alert_not_posted'], $this->logger->warnings);
    }

    private function handler(SpyStaffAlertChannel $channel): PostApworldIncidentToStaffChannelHandler
    {
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn(
            Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable()),
        );

        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(static fn (string $id): ?User => 'admin-1' === $id
            ? new User(
                'admin-1',
                'jean@example.com',
                'jean@example.com',
                'Jean',
                'hash',
                ['ROLE_USER', 'ROLE_ADMIN'],
                new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                new \DateTimeImmutable('2026-01-01T00:00:00Z'),
                new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            )
            : null);

        return new PostApworldIncidentToStaffChannelHandler(
            $this->incidents,
            $games,
            $users,
            new StaffAlertFactory('https://archilan.fr'),
            $channel,
            $this->logger,
        );
    }
}
