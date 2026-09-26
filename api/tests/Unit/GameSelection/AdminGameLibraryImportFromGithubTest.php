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
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Regression guard: importing an apworld from a pre-selected GitHub asset must keep the release tag
 * (previously the tag was dropped, leaving the version null). Since story 38.6 the import creates a
 * candidate: the tag travels with it and becomes the deployed version when the candidate is promoted.
 */
final class AdminGameLibraryImportFromGithubTest extends TestCase
{
    private InMemoryApworldCandidateRepository $candidates;

    protected function setUp(): void
    {
        $this->candidates = new InMemoryApworldCandidateRepository();
    }

    public function testImportFromGithubCreatesACandidateCarryingTheTag(): void
    {
        $game = Game::create(
            'Hollow Knight',
            'hollow-knight',
            'A platformer.',
            null,
            'Hollow Knight cover',
            '',
            Game::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable(),
        );
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/nicholasb/hollow-knight');

        $library = $this->makeLibrary($game);

        $result = $library->importFromGithub(
            $game->getId(),
            'https://github.com/nicholasb/hollow-knight/releases/download/v2.3.4/hollow-knight.apworld',
            'hollow-knight.apworld',
            '2.3.4',
        );

        self::assertTrue($result['found']);
        self::assertSame([], $result['errors']);
        self::assertNull($game->getApworldDeployedVersion(), 'the deployed version moves on promotion, not on upload');
        self::assertNull($game->getApworldHash());

        $candidate = $this->candidates->findTestingForGame($game->getId());
        self::assertSame('2.3.4', $candidate?->getVersionTag());
        self::assertSame('deadbeef', $candidate->getApworldHash());

        $payload = $result['game'] ?? [];
        self::assertIsArray($payload['apworldCandidate'] ?? null);
        self::assertSame('testing', $payload['apworldCandidate']['status']);
        self::assertSame('2.3.4', $payload['apworldCandidate']['versionTag']);
    }

    public function testImportFromGithubWithoutTagLeavesDeployedVersionNull(): void
    {
        $game = Game::create(
            'Hollow Knight',
            'hollow-knight',
            'A platformer.',
            null,
            'Hollow Knight cover',
            '',
            Game::AVAILABILITY_AVAILABLE,
            new \DateTimeImmutable(),
        );
        $game->updateCatalogueMetadata(sourceUrl: 'https://example.com/worlds/hollow-knight.apworld');

        $library = $this->makeLibrary($game);

        $result = $library->importFromGithub(
            $game->getId(),
            'https://example.com/worlds/hollow-knight.apworld',
            'hollow-knight.apworld',
            null,
        );

        self::assertTrue($result['found']);
        self::assertSame([], $result['errors']);
        self::assertNull($game->getApworldDeployedVersion());
        self::assertNull($this->candidates->findTestingForGame($game->getId())?->getVersionTag());
    }

    private function makeLibrary(Game $game): AdminGameLibrary
    {
        $repository = self::createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturn($game);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('uploadApworld')->willReturn([
            'storageKey' => 'storage-key',
            'hash' => 'deadbeef',
            'archipelagoGameName' => 'Hollow Knight',
            'defaultYaml' => 'game: Hollow Knight',
            'optionTypes' => [],
        ]);

        $minio = self::createStub(MinioStorageInterface::class);
        $minio->method('exists')->willReturn(false);

        $usage = self::createStub(GameUsageCounterInterface::class);
        $usage->method('count')->willReturn(0);

        $checker = new ApworldVersionChecker(
            new MockHttpClient([new MockResponse('apworld-bytes')]),
            new NullLogger(),
            'ghp_test_token',
        );

        $normalizer = new InstallStepsNormalizer();

        return new AdminGameLibrary(
            $repository,
            self::createStub(AdminGameListQueryInterface::class),
            new NullLogger(),
            $runner,
            new MockClock(),
            $checker,
            $usage,
            new GamePlatformResolver(self::createStub(IgdbHttpClientInterface::class), new NullLogger()),
            $normalizer,
            new GameTutorialSeeder(self::createStub(GameCatalogLinksProviderInterface::class), $normalizer),
            new InstallStepsReader(),
            new SubmitApworldCandidate($repository, $this->candidates, $runner, $minio, new MockClock(), new NullLogger(), 'apworlds'),
            $this->candidates,
        );
    }
}
