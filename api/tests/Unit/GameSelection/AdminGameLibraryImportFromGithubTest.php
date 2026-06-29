<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\CatalogSync\Application\ApworldVersionChecker;
use App\GameSelection\Application\AdminGameLibrary;
use App\GameSelection\Application\AdminGameListQueryInterface;
use App\GameSelection\Application\GameCatalogLinksProviderInterface;
use App\GameSelection\Application\GamePlatformResolver;
use App\GameSelection\Application\GameTutorialSeeder;
use App\GameSelection\Application\GameUsageCounterInterface;
use App\GameSelection\Application\InstallStepsNormalizer;
use App\GameSelection\Application\InstallStepsReader;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\GameSelection\Infrastructure\IgdbHttpClientInterface;
use App\Sessions\Application\RunnerGatewayInterface;
use App\Shared\Infrastructure\MinioStorageInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Regression guard: importing an apworld from a pre-selected GitHub asset must record the
 * release tag as the deployed version (previously the tag was dropped, leaving the version null).
 */
final class AdminGameLibraryImportFromGithubTest extends TestCase
{
    public function testImportFromGithubRecordsTagAsDeployedVersion(): void
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
        self::assertSame('2.3.4', $game->getApworldDeployedVersion());

        $payload = $result['game'] ?? [];
        self::assertSame('2.3.4', $payload['apworldDeployedVersion']);
        // Status stays "unknown" until the GitHub check fills apworldLatestVersion; the import
        // only records the deployed side. Once "Vérifier les mises à jour" runs it resolves.
        self::assertSame(Game::UPDATE_STATUS_UNKNOWN, $payload['updateStatus']);
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
    }

    private function makeLibrary(Game $game): AdminGameLibrary
    {
        $repository = $this->createStub(GameRepositoryInterface::class);
        $repository->method('findById')->willReturn($game);

        $runner = $this->createStub(RunnerGatewayInterface::class);
        $runner->method('uploadApworld')->willReturn([
            'storageKey' => 'storage-key',
            'hash' => 'deadbeef',
            'archipelagoGameName' => 'Hollow Knight',
            'defaultYaml' => 'game: Hollow Knight',
            'optionTypes' => [],
        ]);

        $minio = $this->createStub(MinioStorageInterface::class);
        $minio->method('exists')->willReturn(false);

        $usage = $this->createStub(GameUsageCounterInterface::class);
        $usage->method('count')->willReturn(0);

        $checker = new ApworldVersionChecker(
            new MockHttpClient([new MockResponse('apworld-bytes')]),
            new NullLogger(),
            'ghp_test_token',
        );

        $normalizer = new InstallStepsNormalizer();

        return new AdminGameLibrary(
            $repository,
            $this->createStub(AdminGameListQueryInterface::class),
            new NullLogger(),
            $runner,
            $minio,
            'apworlds',
            $checker,
            $usage,
            new GamePlatformResolver($this->createStub(IgdbHttpClientInterface::class), new NullLogger()),
            $normalizer,
            new GameTutorialSeeder($this->createStub(GameCatalogLinksProviderInterface::class), $normalizer),
            new InstallStepsReader($minio, 'media', 3600),
        );
    }
}
