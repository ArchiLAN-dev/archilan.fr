<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection\Application\Command;

use App\GameSelection\Application\Command\GameYamlTestOutcome;
use App\GameSelection\Application\Command\StartGameYamlTest;
use App\GameSelection\Application\Port\YamlTestQuotaInterface;
use App\GameSelection\Application\Support\GameYamlTestTicket;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\GameSelection\Infrastructure\Adapter\RateLimitedYamlTestQuota;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Story 9.57 AC 5: every test is a real generation on the shared runner, so a player gets a few per window.
 */
final class StartGameYamlTestTest extends TestCase
{
    private Game $game;

    protected function setUp(): void
    {
        NullRunnerGateway::reset();
        $now = new \DateTimeImmutable('2026-10-19 20:00:00');
        $this->game = Game::create('Hollow Knight', 'hollow-knight', 'desc', null, 'cover', '', Game::AVAILABILITY_AVAILABLE, $now);
        $this->game->configureApworld('hash-hk.apworld', 'hash-hk', 'Hollow Knight', "name: Player\n", $now);
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
    }

    public function testAPlayerGetsFiveTestsPerTenMinutes(): void
    {
        $command = $this->command($this->limitedQuota());

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(GameYamlTestOutcome::Started, $command->start('user-1', 'game-1', "name: Jean\n")->outcome);
        }

        self::assertSame(GameYamlTestOutcome::TooManyTests, $command->start('user-1', 'game-1', "name: Jean\n")->outcome);
        self::assertSame(GameYamlTestOutcome::Started, $command->start('user-2', 'game-1', "name: Jean\n")->outcome, 'the allowance is per player');
    }

    public function testASpentAllowanceNeverReachesTheRunner(): void
    {
        $command = $this->command(new class implements YamlTestQuotaInterface {
            public function consume(string $userId): bool
            {
                return false;
            }
        });

        self::assertSame(GameYamlTestOutcome::TooManyTests, $command->start('user-1', 'game-1', "name: Jean\n")->outcome);
        self::assertNull(NullRunnerGateway::$lastSlotPreflight);
    }

    public function testAnUnavailableGameIsNotTested(): void
    {
        $this->game->update('Hollow Knight', 'hollow-knight', 'desc', null, 'cover', '', Game::AVAILABILITY_UNAVAILABLE, new \DateTimeImmutable('2026-10-19 20:00:00'));

        self::assertSame(GameYamlTestOutcome::UnknownGame, $this->command($this->limitedQuota())->start('user-1', 'game-1', "name: Jean\n")->outcome);
        self::assertNull(NullRunnerGateway::$lastSlotPreflight);
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

    private function command(YamlTestQuotaInterface $quota): StartGameYamlTest
    {
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($this->game);

        return new StartGameYamlTest($games, new NullRunnerGateway(), new GameYamlTestTicket('secret'), $quota);
    }

    /** The production adapter on the production policy, with in-memory storage and lock. */
    private function limitedQuota(): YamlTestQuotaInterface
    {
        $factory = new RateLimiterFactory(
            ['id' => 'game_yaml_test', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '10 minutes'],
            new InMemoryStorage(),
            new LockFactory(new InMemoryStore()),
        );

        return new RateLimitedYamlTestQuota($factory);
    }
}
