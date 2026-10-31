<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\BackfillApworldPreflights;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BackfillApworldPreflightsTest extends TestCase
{
    private const array VERDICT_NONE = ['status' => '', 'error' => '', 'checkedAt' => '', 'overridden' => false, 'blocks' => false];
    private const array VERDICT_PASSED = ['status' => 'passed', 'error' => '', 'checkedAt' => '2026-07-30T12:00:00Z', 'overridden' => false, 'blocks' => false];

    public function testRunQueuesOnlyUncheckedApworlds(): void
    {
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn([
            'aaa' => self::VERDICT_NONE,
            'bbb' => self::VERDICT_PASSED,
        ]);
        $runner->method('runApworldPreflight')->willReturn(true);

        $result = new BackfillApworldPreflights($runner, self::createStub(ServedApworldsQueryInterface::class), new NullLogger())->run();

        self::assertSame(2, $result->total);
        self::assertSame(1, $result->requested);
        self::assertSame(1, $result->skipped);
        self::assertSame(0, $result->failed);
    }

    public function testRunWithAllReChecksEverything(): void
    {
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn([
            'aaa' => self::VERDICT_NONE,
            'bbb' => self::VERDICT_PASSED,
        ]);
        $runner->method('runApworldPreflight')->willReturn(true);

        $result = new BackfillApworldPreflights($runner, self::createStub(ServedApworldsQueryInterface::class), new NullLogger())->run(all: true);

        self::assertSame(2, $result->requested);
        self::assertSame(0, $result->skipped);
    }

    // Review of epic 44: --all never replaces a pass obtained with the admin's file by a run without it.
    public function testRunWithAllLeavesAPassObtainedWithTheFileAlone(): void
    {
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn([
            'aaa' => self::VERDICT_PASSED,
            'rom' => [...self::VERDICT_PASSED, 'withRequiredFiles' => true],
        ]);
        $runner->method('runApworldPreflight')->willReturn(true);

        $result = new BackfillApworldPreflights($runner, self::createStub(ServedApworldsQueryInterface::class), new NullLogger())->run(all: true);

        self::assertSame(1, $result->requested);
        self::assertSame(1, $result->skipped);
    }

    public function testRunCountsRunnerErrors(): void
    {
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn(['aaa' => self::VERDICT_NONE]);
        $runner->method('runApworldPreflight')->willReturn(false);

        $result = new BackfillApworldPreflights($runner, self::createStub(ServedApworldsQueryInterface::class), new NullLogger())->run();

        self::assertSame(0, $result->requested);
        self::assertSame(1, $result->failed);
    }
}
