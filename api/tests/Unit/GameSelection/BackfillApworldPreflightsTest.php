<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\BackfillApworldPreflights;
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

        $result = new BackfillApworldPreflights($runner, new NullLogger())->run();

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

        $result = new BackfillApworldPreflights($runner, new NullLogger())->run(all: true);

        self::assertSame(2, $result->requested);
        self::assertSame(0, $result->skipped);
    }

    public function testRunCountsRunnerErrors(): void
    {
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn(['aaa' => self::VERDICT_NONE]);
        $runner->method('runApworldPreflight')->willReturn(false);

        $result = new BackfillApworldPreflights($runner, new NullLogger())->run();

        self::assertSame(0, $result->requested);
        self::assertSame(1, $result->failed);
    }
}
