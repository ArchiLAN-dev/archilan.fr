<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\SweepApworldCatalog;
use App\GameSelection\Application\Handler\SweepApworldCatalogHandler;
use App\GameSelection\Application\Message\SweepApworldCatalogMessage;
use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Presentation\Command\SweepApworldCatalogCommand;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Story 38.9: the nightly message and the console command both run one batch of the sweep.
 */
final class SweepApworldCatalogEntryPointsTest extends TestCase
{
    /** @var list<string> */
    private array $launched = [];

    public function testTheNightlyRunUsesTheConfiguredBatchSize(): void
    {
        new SweepApworldCatalogHandler($this->sweep(5), 3, new NullLogger())(new SweepApworldCatalogMessage());

        self::assertCount(3, $this->launched);
    }

    public function testTheConsoleRunsABatchOfTheRequestedSize(): void
    {
        $tester = new CommandTester(new SweepApworldCatalogCommand($this->sweep(5), 25));

        self::assertSame(Command::SUCCESS, $tester->execute(['--batch' => '2']));
        self::assertCount(2, $this->launched);
        self::assertStringContainsString('2 apworld test(s) launched on ghcr.io/archilan-dev/archipelago:0.16.1.', $tester->getDisplay());
    }

    public function testTheConsoleSaysWhenTheRunnerCannotTell(): void
    {
        $tester = new CommandTester(new SweepApworldCatalogCommand($this->sweep(5, runnerDown: true), 25));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Runner unavailable: nothing launched.', $tester->getDisplay());
    }

    private function sweep(int $games, bool $runnerDown = false): SweepApworldCatalog
    {
        $served = [];
        $verdicts = [];
        foreach (range(1, $games) as $i) {
            $served[] = new ServedApworld('g-'.$i, 'h-'.$i);
            $verdicts['h-'.$i] = ['status' => 'passed', 'error' => '', 'checkedAt' => '2026-01-0'.$i.'T00:00:00Z', 'overridden' => false, 'blocks' => false];
        }
        $servedQuery = self::createStub(ServedApworldsQueryInterface::class);
        $servedQuery->method('servedApworlds')->willReturn($served);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchRuntime')->willReturn($runnerDown ? null : ['apImage' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'apImageId' => 'sha256:current']);
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);
        $runner->method('runApworldPreflight')->willReturnCallback(function (string $hash): bool {
            $this->launched[] = $hash;

            return true;
        });

        return new SweepApworldCatalog($servedQuery, $runner, new InMemoryApworldCandidateRepository(), new NullLogger());
    }
}
