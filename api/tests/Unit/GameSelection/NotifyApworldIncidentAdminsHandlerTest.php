<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\Community\Application\Query\CommunityAdminIdsQueryInterface;
use App\GameSelection\Application\Handler\NotifyApworldIncidentAdminsHandler;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotifyApworldIncidentAdminsHandlerTest extends TestCase
{
    private InMemoryApworldIncidentRepository $incidents;
    private SpyNotifier $notifier;

    protected function setUp(): void
    {
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->incidents->save(ApworldIncident::open(
            'incident-1',
            'game-1',
            'hash-1',
            ApworldIncidentType::PreflightFailed,
            'boom',
            new \DateTimeImmutable('2026-09-24 10:00:00+00:00'),
        ));
        $this->notifier = new SpyNotifier();
    }

    public function testNotifiesEveryAdminOnce(): void
    {
        $this->handler(['admin-1', 'admin-2'], 'Crystal Project')(new NotifyApworldIncidentAdminsJob('incident-1'));

        self::assertSame(['admin-1', 'admin-2'], array_column($this->notifier->sent, 'recipientId'));
        foreach ($this->notifier->sent as $notification) {
            self::assertSame('apworld_incident_opened', $notification['type']);
            self::assertSame([
                'incidentId' => 'incident-1',
                'gameId' => 'game-1',
                'gameName' => 'Crystal Project',
                'incidentType' => 'preflight_failed',
            ], $notification['payload']);
        }
    }

    public function testMissingIncidentIsIgnored(): void
    {
        $this->handler(['admin-1'], 'Crystal Project')(new NotifyApworldIncidentAdminsJob('unknown'));

        self::assertSame([], $this->notifier->sent);
    }

    public function testAGameThatVanishedKeepsTheNotificationReadable(): void
    {
        $this->handler(['admin-1'], null)(new NotifyApworldIncidentAdminsJob('incident-1'));

        self::assertCount(1, $this->notifier->sent);
        self::assertSame('game-1', $this->notifier->sent[0]['payload']['gameName']);
    }

    /**
     * @param list<string> $adminIds
     */
    private function handler(array $adminIds, ?string $gameName): NotifyApworldIncidentAdminsHandler
    {
        $admins = self::createStub(CommunityAdminIdsQueryInterface::class);
        $admins->method('adminUserIds')->willReturn($adminIds);

        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn(null === $gameName
            ? null
            : Game::create($gameName, 'crystal-project', 'A game.', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable()));

        return new NotifyApworldIncidentAdminsHandler($this->incidents, $games, $admins, $this->notifier, new NullLogger());
    }
}
