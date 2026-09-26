<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\ApworldIncidentRecordOutcome;
use App\GameSelection\Application\Command\DefaultYamlFailureReport;
use App\GameSelection\Application\Command\DefaultYamlFailureVerdict;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Application\Command\ReportDefaultYamlFailure;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Story 38.4: a real generation that fails with the game's default YAML accuses the apworld, even when
 * its import test passed. A YAML the player changed accuses only their config.
 */
final class ReportDefaultYamlFailureTest extends TestCase
{
    private const string DEFAULT_YAML = "name: Player{number}\ngame: Crystal Project\nCrystal Project:\n  goal:\n    astley: 50\n    true_astley: 0\n";

    private Game $game;
    private InMemoryApworldIncidentRepository $incidents;
    private WarningCollectingLogger $logger;

    protected function setUp(): void
    {
        $this->game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->game->configureApworld('hash-1.apworld', 'hash-1', 'Crystal Project', self::DEFAULT_YAML, new \DateTimeImmutable());
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->logger = new WarningCollectingLogger();
    }

    public function testTheDefaultYamlOnTheServedHashOpensAnIncident(): void
    {
        $report = $this->report('hash-1', "name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: astley\n");

        self::assertSame(DefaultYamlFailureVerdict::ApworldAccused, $report->verdict);
        self::assertSame(ApworldIncidentRecordOutcome::Opened, $report->recording?->outcome);
        $incident = $this->incidents->all()[0];
        self::assertSame(ApworldIncidentType::DefaultYamlFailure, $incident->getType());
        self::assertSame($this->game->getId(), $incident->getGameId());
        self::assertSame('hash-1', $incident->getApworldHash());
        self::assertSame('Fill.FillError: no location left', $incident->getError());
        self::assertSame(1, $this->incidents->flushes);
    }

    public function testTenPlayersFailingTheSameWayMakeOneIncident(): void
    {
        foreach (range(1, 10) as $i) {
            $this->report('hash-1', self::DEFAULT_YAML);
        }

        self::assertCount(1, $this->incidents->all());
        self::assertSame(10, $this->incidents->all()[0]->getOccurrences());
    }

    public function testAnEmptyYamlIsTheDefaultOne(): void
    {
        // A slot never configured is generated with the game's default YAML.
        self::assertSame(DefaultYamlFailureVerdict::ApworldAccused, $this->report('hash-1', '')->verdict);
    }

    public function testASlotWithoutRecordedHashIsOnTheServedOne(): void
    {
        self::assertSame(DefaultYamlFailureVerdict::ApworldAccused, $this->report(null, self::DEFAULT_YAML)->verdict);
        self::assertSame('hash-1', $this->incidents->all()[0]->getApworldHash());
    }

    public function testACustomYamlAccusesNothing(): void
    {
        $report = $this->report('hash-1', "name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: true_astley\n");

        self::assertSame(DefaultYamlFailureVerdict::CustomYaml, $report->verdict);
        self::assertNull($report->recording);
        self::assertSame([], $this->incidents->all());
        self::assertSame(0, $this->incidents->flushes);
    }

    public function testAnOldHashAccusesNothing(): void
    {
        $report = $this->report('hash-0', self::DEFAULT_YAML);

        self::assertSame(DefaultYamlFailureVerdict::NotServedHash, $report->verdict);
        self::assertSame([], $this->incidents->all());
    }

    public function testAnUnreadableYamlAccusesNothingAndWarns(): void
    {
        $report = $this->report('hash-1', "game: [unclosed\n");

        self::assertSame(DefaultYamlFailureVerdict::UnreadableYaml, $report->verdict);
        self::assertSame([], $this->incidents->all());
        self::assertCount(1, $this->logger->warnings);
    }

    public function testAGameWithoutApworldAccusesNothing(): void
    {
        $bare = Game::create('Other', 'other', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());

        self::assertSame(DefaultYamlFailureVerdict::NotServedHash, $this->report('hash-1', self::DEFAULT_YAML, $bare)->verdict);
        self::assertSame(DefaultYamlFailureVerdict::UnknownGame, $this->report('hash-1', self::DEFAULT_YAML, null)->verdict);
    }

    /**
     * @param Game|false|null $game false for the game of the test, null for an unknown game
     */
    private function report(?string $hash, string $yaml, Game|false|null $game = false): DefaultYamlFailureReport
    {
        $resolved = false === $game ? $this->game : $game;
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($resolved);

        $command = new ReportDefaultYamlFailure($games, new RecordApworldIncident($this->incidents, new MockClock('2026-09-26 21:00:00+00:00')), $this->incidents, $this->logger);

        return $command->report($this->game->getId(), $hash, $yaml, 'Fill.FillError: no location left');
    }
}
