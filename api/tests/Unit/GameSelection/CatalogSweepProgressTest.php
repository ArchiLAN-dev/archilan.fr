<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Query\CatalogSweepProgress;
use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.9: how far the rolling test has come on the image in use.
 */
final class CatalogSweepProgressTest extends TestCase
{
    private const string IMAGE = 'ghcr.io/archilan-dev/archipelago:0.16.1';

    public function testCountsTheApworldsAlreadyTestedOnTheCurrentImage(): void
    {
        $progress = $this->progress(
            [
                new ServedApworld('g-1', 'on-current'),
                new ServedApworld('g-2', 'on-old'),
                new ServedApworld('g-3', 'never'),
                new ServedApworld('g-4', 'on-current'),
                new ServedApworld('g-5', 'disabled', disabled: true),
            ],
            [
                'on-current' => ['status' => 'passed', 'error' => '', 'checkedAt' => '2026-09-26T05:00:00Z', 'overridden' => false, 'blocks' => false, 'image' => self::IMAGE, 'imageId' => 'sha256:current'],
                'on-old' => ['status' => 'failed', 'error' => '', 'checkedAt' => '2026-09-01T05:00:00Z', 'overridden' => false, 'blocks' => true, 'image' => 'ghcr.io/archilan-dev/archipelago:0.16.0', 'imageId' => 'sha256:old'],
                'disabled' => ['status' => 'passed', 'error' => '', 'checkedAt' => '2026-09-26T05:00:00Z', 'overridden' => false, 'blocks' => false, 'image' => self::IMAGE, 'imageId' => 'sha256:current'],
            ],
        );

        self::assertNotNull($progress);
        self::assertSame(self::IMAGE, $progress->currentImage);
        self::assertSame(1, $progress->testedOnCurrentImage, 'an apworld served by two games counts once');
        self::assertSame(3, $progress->total, 'a disabled game is not part of the cycle');
    }

    public function testNothingIsClaimedWhenTheRunnerDoesNotSay(): void
    {
        self::assertNull($this->progress([new ServedApworld('g-1', 'h-1')], [], runtime: null));
    }

    /**
     * @param list<ServedApworld>                                                                                                                                $served
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image?: string|null, imageId?: string|null}> $verdicts
     * @param array{apImage: string, apImageId: string|null}|null                                                                                                $runtime
     */
    private function progress(array $served, array $verdicts, ?array $runtime = ['apImage' => self::IMAGE, 'apImageId' => 'sha256:current']): ?\App\GameSelection\Application\Query\CatalogSweepProgressView
    {
        $servedQuery = self::createStub(ServedApworldsQueryInterface::class);
        $servedQuery->method('servedApworlds')->willReturn($served);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchRuntime')->willReturn($runtime);
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);

        return new CatalogSweepProgress($servedQuery, $runner)->progress();
    }
}
