<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Application\Command\SubmitApworldCandidate;
use App\GameSelection\Application\Port\GameCatalogLinksProviderInterface;
use App\GameSelection\Application\Port\GameUsageCounterInterface;
use App\GameSelection\Application\Port\IgdbHttpClientInterface;
use App\GameSelection\Application\Query\AdminGameListQueryInterface;
use App\GameSelection\Application\Service\AdminGameLibrary;
use App\GameSelection\Application\Support\GamePlatformResolver;
use App\GameSelection\Application\Support\GameTutorialSeeder;
use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Application\Support\InstallStepsReader;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Story 38.8: the game page says which Archipelago image its apworld was tested on, and whether that
 * is still the image in use.
 */
final class AdminGameLibraryPreflightImageTest extends TestCase
{
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

    /**
     * @param array{image?: string, imageId?: string}             $image
     * @param array{apImage: string, apImageId: string|null}|null $runtime
     *
     * @return array<string, mixed>
     */
    private function detail(array $image, ?array $runtime): array
    {
        $now = new \DateTimeImmutable('2026-09-26');
        $game = Game::create('Crystal Project', 'crystal-project', 'desc', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, $now);
        $game->configureApworld('h1.apworld', 'h1', 'Crystal Project', "game: Crystal Project\n", $now);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn(['h1' => ['status' => 'passed', 'error' => '', 'checkedAt' => '2026-09-26T04:00:00Z', 'overridden' => false, 'blocks' => false] + $image]);
        $runner->method('fetchRuntime')->willReturn($runtime);

        $detail = $this->library($game, $runner)->detail($game->getId());
        self::assertIsArray($detail);

        return $detail;
    }

    private function library(Game $game, ?RunnerGatewayInterface $runner = null): AdminGameLibrary
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturn($game);

        $usage = self::createStub(GameUsageCounterInterface::class);
        $usage->method('count')->willReturn(0);

        $normalizer = new InstallStepsNormalizer();

        return new AdminGameLibrary(
            $repository,
            self::createStub(AdminGameListQueryInterface::class),
            new NullLogger(),
            $runner ?? self::createStub(RunnerGatewayInterface::class),
            new MockClock(),
            new ApworldVersionChecker(new MockHttpClient([]), new NullLogger(), 'token'),
            $usage,
            new GamePlatformResolver(self::createStub(IgdbHttpClientInterface::class), new NullLogger()),
            $normalizer,
            new GameTutorialSeeder(self::createStub(GameCatalogLinksProviderInterface::class), $normalizer),
            new InstallStepsReader(),
            new SubmitApworldCandidate(self::createStub(GameRepositoryInterface::class), new InMemoryApworldCandidateRepository(), self::createStub(RunnerGatewayInterface::class), self::createStub(MinioStorageInterface::class), new MockClock(), new NullLogger(), 'apworlds'),
            new InMemoryApworldCandidateRepository(),
        );
    }
}
