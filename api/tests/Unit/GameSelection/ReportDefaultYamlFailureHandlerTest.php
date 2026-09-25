<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Application\Command\ReportDefaultYamlFailure;
use App\GameSelection\Application\Handler\ReportDefaultYamlFailureHandler;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\ReportDefaultYamlFailureJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Story 38.4: an incident opened by a real generation alerts exactly like one opened by the import test.
 */
final class ReportDefaultYamlFailureHandlerTest extends TestCase
{
    private const string DEFAULT_YAML = "game: Crystal Project\nCrystal Project:\n  goal: astley\n";

    private Game $game;
    private InMemoryApworldIncidentRepository $incidents;
    private SpyMessageBus $bus;

    protected function setUp(): void
    {
        $this->game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->game->configureApworld('hash-1.apworld', 'hash-1', 'Crystal Project', self::DEFAULT_YAML, new \DateTimeImmutable());
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->bus = new SpyMessageBus($this->incidents);
    }

    public function testAnOpenedIncidentAlertsTheAdminsAndTheStaffAfterItsFlush(): void
    {
        $this->handle(self::DEFAULT_YAML);

        $incidentId = $this->incidents->all()[0]->getId();
        self::assertEquals([
            new NotifyApworldIncidentAdminsJob($incidentId),
            new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Opened),
        ], $this->bus->messages());
        self::assertSame([1, 1], array_column($this->bus->dispatched, 'flushesBefore'));
    }

    public function testARecurrenceAlertsNobody(): void
    {
        $this->handle(self::DEFAULT_YAML);
        $this->bus->dispatched = [];

        $this->handle(self::DEFAULT_YAML);

        self::assertSame(2, $this->incidents->all()[0]->getOccurrences());
        self::assertSame([], $this->bus->dispatched);
    }

    public function testACustomYamlAlertsNobody(): void
    {
        $this->handle("game: Crystal Project\nCrystal Project:\n  goal: true_astley\n");

        self::assertSame([], $this->bus->dispatched);
    }

    private function handle(string $yaml): void
    {
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($this->game);
        $report = new ReportDefaultYamlFailure($games, new RecordApworldIncident($this->incidents, new MockClock()), $this->incidents, new NullLogger());

        new ReportDefaultYamlFailureHandler($report, new ApworldIncidentAlertDispatcher($this->bus))(
            new ReportDefaultYamlFailureJob($this->game->getId(), 'hash-1', $yaml, 'Fill.FillError: boom'),
        );
    }
}
