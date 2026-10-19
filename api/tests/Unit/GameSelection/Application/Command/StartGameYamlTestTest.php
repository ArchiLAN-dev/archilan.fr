<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection\Application\Command;

use App\GameSelection\Application\Command\GameYamlTestOutcome;
use App\GameSelection\Application\Command\StartGameYamlTest;
use App\GameSelection\Application\Support\GameYamlTestTicket;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * Story 9.57 AC 5: every test is a real generation on the shared runner, so a player gets a few per window.
 */
final class StartGameYamlTestTest extends TestCase
{
    private MockClock $clock;
    private StartGameYamlTest $command;

    protected function setUp(): void
    {
        NullRunnerGateway::reset();
        $now = new \DateTimeImmutable('2026-10-19 20:00:00');
        $this->clock = new MockClock($now);

        $game = Game::create('Hollow Knight', 'hollow-knight', 'desc', null, 'cover', '', Game::AVAILABILITY_AVAILABLE, $now);
        $game->configureApworld('hash-hk.apworld', 'hash-hk', 'Hollow Knight', "name: Player\n", $now);
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($game);

        $this->command = new StartGameYamlTest($games, new NullRunnerGateway(), new GameYamlTestTicket('secret'), new ArrayAdapter(), $this->clock);
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
    }

    public function testAPlayerGetsAFewTestsPerWindow(): void
    {
        for ($i = 0; $i < StartGameYamlTest::MAX_TESTS_PER_WINDOW; ++$i) {
            self::assertSame(GameYamlTestOutcome::Started, $this->command->start('user-1', 'game-1', "name: Jean\n")->outcome);
        }

        self::assertSame(GameYamlTestOutcome::TooManyTests, $this->command->start('user-1', 'game-1', "name: Jean\n")->outcome);
        self::assertSame(GameYamlTestOutcome::Started, $this->command->start('user-2', 'game-1', "name: Jean\n")->outcome, 'the cap is per player');

        $this->clock->sleep(StartGameYamlTest::WINDOW_SECONDS);
        self::assertSame(GameYamlTestOutcome::Started, $this->command->start('user-1', 'game-1', "name: Jean\n")->outcome, 'the next window starts afresh');
    }

    public function testATestTheRunnerDidNotTakeDoesNotCount(): void
    {
        NullRunnerGateway::$slotPreflightUnavailable = true;
        for ($i = 0; $i < StartGameYamlTest::MAX_TESTS_PER_WINDOW; ++$i) {
            self::assertSame(GameYamlTestOutcome::RunnerUnavailable, $this->command->start('user-1', 'game-1', "name: Jean\n")->outcome);
        }

        NullRunnerGateway::$slotPreflightUnavailable = false;
        self::assertSame(GameYamlTestOutcome::Started, $this->command->start('user-1', 'game-1', "name: Jean\n")->outcome);
    }

    public function testATicketOnlyReadsBackForItsPlayerAndGame(): void
    {
        $tickets = new GameYamlTestTicket('secret');
        $ticket = $tickets->issue('user-1', 'game-1', 'job.with.dots');

        self::assertSame('job.with.dots', $tickets->jobId('user-1', 'game-1', $ticket));
        self::assertNull($tickets->jobId('user-2', 'game-1', $ticket));
        self::assertNull($tickets->jobId('user-1', 'game-2', $ticket));
        self::assertNull($tickets->jobId('user-1', 'game-1', 'job.with.dots'));
        self::assertNull($tickets->jobId('user-1', 'game-1', 'no-separator'));
    }
}
