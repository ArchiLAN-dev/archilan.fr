<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Port\GameUsageCounterInterface;
use App\GameSelection\Application\Service\AdminGameLibrary;
use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.8: the game page says which Archipelago image its apworld was tested on, and whether that
 * is still the image in use.
 */
final class AdminGameLibraryPreflightImageTest extends TestCase
{
    use BuildsAdminGameLibrary;

    private const string CURRENT = 'ghcr.io/archilan-dev/archipelago:0.16.1';

    public function testAVerdictOnTheImageInUseSaysSo(): void
    {
        $detail = $this->detail(['image' => self::CURRENT, 'imageId' => 'sha256:new'], ['apImage' => self::CURRENT, 'apImageId' => 'sha256:new']);

        self::assertIsArray($detail['apworldPreflight'] ?? null);
        self::assertSame(self::CURRENT, $detail['apworldPreflight']['image'] ?? null);
        self::assertSame(['apImage' => self::CURRENT, 'apImageId' => 'sha256:new'], $detail['archipelagoRuntime'] ?? null);
        self::assertTrue($detail['apworldPreflightOnCurrentImage'] ?? null);
    }

    public function testDetailFlagsAVerdictFromAnOlderImage(): void
    {
        $detail = $this->detail(['image' => 'ghcr.io/archilan-dev/archipelago:0.16.0', 'imageId' => 'sha256:old'], ['apImage' => self::CURRENT, 'apImageId' => 'sha256:new']);

        self::assertFalse($detail['apworldPreflightOnCurrentImage'] ?? null);
    }

    public function testAVerdictWithoutImageCountsAsFromAnOlderOne(): void
    {
        $detail = $this->detail([], ['apImage' => self::CURRENT, 'apImageId' => 'sha256:new']);

        self::assertFalse($detail['apworldPreflightOnCurrentImage'] ?? null);
    }

    public function testNothingIsClaimedWhenTheImageInUseIsUnknown(): void
    {
        $detail = $this->detail(['image' => self::CURRENT, 'imageId' => 'sha256:new'], null);

        self::assertArrayHasKey('apworldPreflightOnCurrentImage', $detail);
        self::assertNull($detail['apworldPreflightOnCurrentImage']);
        self::assertNull($detail['archipelagoRuntime'] ?? null);
    }

    public function testASkippedVerdictClaimsNoImageAndAsksForNone(): void
    {
        // Story 38.8 review: nothing ran, so nothing was tested on any image.
        $detail = $this->detail(['image' => self::CURRENT, 'imageId' => 'sha256:new'], ['apImage' => self::CURRENT, 'apImageId' => 'sha256:new'], status: 'skipped', runtimeCalls: self::never());

        self::assertArrayHasKey('apworldPreflightOnCurrentImage', $detail);
        self::assertNull($detail['apworldPreflightOnCurrentImage']);
        self::assertArrayHasKey('archipelagoRuntime', $detail);
        self::assertNull($detail['archipelagoRuntime']);
    }

    public function testAPendingVerdictDoesNotAskForTheImageInUse(): void
    {
        // Polled every 10 s while pending: no /runtime call for data the page hides.
        $detail = $this->detail([], ['apImage' => self::CURRENT, 'apImageId' => 'sha256:new'], status: 'pending', runtimeCalls: self::never());

        self::assertArrayHasKey('apworldPreflightOnCurrentImage', $detail);
        self::assertNull($detail['apworldPreflightOnCurrentImage']);
    }

    public function testASaveAnswersWithTheVerdictToo(): void
    {
        // Every save replaces the cached game with its answer: without the verdict the panel fell back
        // to "Jamais testée" until the next reload.
        $game = $this->game();
        $result = $this->library($game, $this->runner(['image' => self::CURRENT, 'imageId' => 'sha256:new'], ['apImage' => self::CURRENT, 'apImageId' => 'sha256:new']))->saveNotes($game->getId(), 'note');

        $payload = $result['game'] ?? null;
        self::assertIsArray($payload);
        self::assertIsArray($payload['apworldPreflight'] ?? null);
        self::assertTrue($payload['apworldPreflightOnCurrentImage'] ?? null);
    }

    /**
     * @param array{image?: string, imageId?: string}             $image
     * @param array{apImage: string, apImageId: string|null}|null $runtime
     *
     * @return array<string, mixed>
     */
    private function detail(array $image, ?array $runtime, string $status = 'passed', ?InvocationOrder $runtimeCalls = null): array
    {
        $game = $this->game();
        $detail = $this->library($game, $this->runner($image, $runtime, $status, $runtimeCalls))->detail($game->getId());
        self::assertIsArray($detail);

        return $detail;
    }

    private function game(): Game
    {
        $now = new \DateTimeImmutable('2026-09-26');
        $game = Game::create('Crystal Project', 'crystal-project', 'desc', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, $now);
        $game->configureApworld('h1.apworld', 'h1', 'Crystal Project', "game: Crystal Project\n", $now);

        return $game;
    }

    /**
     * @param array{image?: string, imageId?: string}             $image
     * @param array{apImage: string, apImageId: string|null}|null $runtime
     */
    private function runner(array $image, ?array $runtime, string $status = 'passed', ?InvocationOrder $runtimeCalls = null): RunnerGatewayInterface
    {
        $verdicts = ['h1' => ['status' => $status, 'error' => '', 'checkedAt' => '2026-09-26T04:00:00Z', 'overridden' => false, 'blocks' => false] + $image];
        if (null === $runtimeCalls) {
            $runner = self::createStub(RunnerGatewayInterface::class);
            $runner->method('fetchRuntime')->willReturn($runtime);
        } else {
            $runner = $this->createMock(RunnerGatewayInterface::class);
            $runner->expects($runtimeCalls)->method('fetchRuntime')->willReturn($runtime);
        }
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);

        return $runner;
    }

    private function library(Game $game, ?RunnerGatewayInterface $runner = null): AdminGameLibrary
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturn($game);

        $usage = self::createStub(GameUsageCounterInterface::class);
        $usage->method('count')->willReturn(0);

        $normalizer = new InstallStepsNormalizer();

        return $this->buildAdminGameLibrary($repository, $runner ?? self::createStub(RunnerGatewayInterface::class));
    }
}
