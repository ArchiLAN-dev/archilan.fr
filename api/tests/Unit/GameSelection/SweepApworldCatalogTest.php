<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\SweepApworldCatalog;
use App\GameSelection\Application\Command\SweepApworldCatalogResult;
use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.9: the nightly batch of the rolling catalogue test.
 */
final class SweepApworldCatalogTest extends TestCase
{
    private const array RUNTIME = ['apImage' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'apImageId' => 'sha256:current'];

    /** @var list<string> */
    private array $launched = [];
    private WarningCollectingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new WarningCollectingLogger();
    }

    public function testLaunchesThePlannedPreflights(): void
    {
        $result = $this->sweep(
            [new ServedApworld('g-1', 'on-current'), new ServedApworld('g-2', 'on-old')],
            [
                'on-current' => $this->verdict('passed', '2026-01-01T00:00:00Z', self::RUNTIME['apImage'], 'sha256:current'),
                'on-old' => $this->verdict('passed', '2026-09-20T00:00:00Z', 'ghcr.io/archilan-dev/archipelago:0.16.0', 'sha256:old'),
            ],
            25,
        );

        self::assertSame(['on-old', 'on-current'], $this->launched);
        self::assertSame(['on-old', 'on-current'], $result->launchedApworldHashes);
        self::assertTrue($result->runnerAvailable);
    }

    public function testDoesNothingWithoutTheCurrentImage(): void
    {
        $result = $this->sweep([new ServedApworld('g-1', 'h-1')], ['h-1' => $this->verdict('passed', '2026-01-01T00:00:00Z', null, null)], 25, runtime: null);

        self::assertFalse($result->runnerAvailable);
        self::assertSame([], $this->launched);
    }

    public function testDoesNothingWithoutVerdicts(): void
    {
        $result = $this->sweep([new ServedApworld('g-1', 'h-1')], [], 25);

        self::assertFalse($result->runnerAvailable);
        self::assertSame([], $this->launched);
    }

    public function testBatchSizeIsClamped(): void
    {
        $served = [];
        $verdicts = [];
        foreach (range(1, 210) as $i) {
            $served[] = new ServedApworld('g-'.$i, 'h-'.$i);
            $verdicts['h-'.$i] = $this->verdict('passed', '2026-01-01T00:00:00Z', null, null);
        }

        $this->sweep($served, $verdicts, 500);
        self::assertCount(SweepApworldCatalog::MAX_BATCH_SIZE, $this->launched);
        self::assertCount(1, $this->logger->warnings, 'an out-of-bounds setting is reported');

        $this->launched = [];
        $this->sweep($served, $verdicts, 0);
        self::assertCount(1, $this->launched);
    }

    public function testLeavesAloneWhatMustNotBeRetested(): void
    {
        $candidateInTest = ApworldCandidate::submit('c-1', 'g-candidate', 'h-new', 'k', 'k', "game: x\n", 'X', 'v2', ApworldCandidateOrigin::Auto, null, new \DateTimeImmutable());

        $this->sweep(
            [
                new ServedApworld('g-disabled', 'h-disabled', disabled: true),
                new ServedApworld('g-candidate', 'h-candidate'),
                new ServedApworld('g-forced', 'h-forced'),
                new ServedApworld('g-running', 'h-running'),
                new ServedApworld('g-skipped', 'h-skipped'),
                new ServedApworld('g-unknown', 'h-unknown'),
                new ServedApworld('g-ok', 'h-ok'),
            ],
            [
                'h-disabled' => $this->verdict('passed', '2026-01-01T00:00:00Z', null, null),
                'h-candidate' => $this->verdict('passed', '2026-01-01T00:00:00Z', null, null),
                'h-forced' => $this->verdict('failed', '2026-01-01T00:00:00Z', null, null, overridden: true),
                'h-running' => $this->verdict('pending', '', null, null),
                'h-skipped' => $this->verdict('skipped', '2026-01-01T00:00:00Z', null, null),
                'h-ok' => $this->verdict('passed', '2026-01-01T00:00:00Z', null, null),
            ],
            25,
            candidates: [$candidateInTest],
        );

        // The apworld the orchestrator does not list (h-unknown) cannot be run either.
        self::assertSame(['h-ok'], $this->launched);
    }

    public function testAForcedVersionThatPassesIsRetestedLikeAnyOther(): void
    {
        // Story 38.10: the override is a force-allow for a failed verdict. Forced while its test was still
        // running, a version that then passed kept it - and was skipped by the rolling test for good. The
        // orchestrator now clears it on a pass; the ones already stuck in production are retested, which
        // is what clears them.
        $this->sweep(
            [new ServedApworld('g-1', 'h-forced-but-passing')],
            ['h-forced-but-passing' => $this->verdict('passed', '2026-01-01T00:00:00Z', null, null, overridden: true)],
            25,
        );

        self::assertSame(['h-forced-but-passing'], $this->launched);
    }

    public function testAnApworldNeverTestedIsPlanned(): void
    {
        // Uploaded before verdicts existed: listed with an empty status.
        $this->sweep([new ServedApworld('g-1', 'h-1')], ['h-1' => $this->verdict('', '', null, null)], 25);

        self::assertSame(['h-1'], $this->launched);
    }

    /**
     * @param list<ServedApworld>                                                                                                                                $served
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image?: string|null, imageId?: string|null}> $verdicts
     * @param array{apImage: string, apImageId: string|null}|null                                                                                                $runtime
     * @param list<ApworldCandidate>                                                                                                                             $candidates
     */
    private function sweep(array $served, array $verdicts, int $batchSize, ?array $runtime = self::RUNTIME, array $candidates = []): SweepApworldCatalogResult
    {
        $servedQuery = self::createStub(ServedApworldsQueryInterface::class);
        $servedQuery->method('servedApworlds')->willReturn($served);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchRuntime')->willReturn($runtime);
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);
        $runner->method('runApworldPreflight')->willReturnCallback(function (string $hash): bool {
            $this->launched[] = $hash;

            return true;
        });
        $candidateRepository = new InMemoryApworldCandidateRepository();
        foreach ($candidates as $candidate) {
            $candidateRepository->save($candidate);
        }

        return new SweepApworldCatalog($servedQuery, $runner, $candidateRepository, $this->logger)->sweep($batchSize);
    }

    /**
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool, image: string|null, imageId: string|null}
     */
    private function verdict(string $status, string $checkedAt, ?string $image, ?string $imageId, bool $overridden = false): array
    {
        return ['status' => $status, 'error' => '', 'checkedAt' => $checkedAt, 'overridden' => $overridden, 'blocks' => false, 'image' => $image, 'imageId' => $imageId];
    }
}
