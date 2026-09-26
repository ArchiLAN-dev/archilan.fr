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
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * One wiring of AdminGameLibrary for its unit tests (story 38.8 review): it had five hand-written
 * copies, each to patch whenever the service gained a dependency.
 */
trait BuildsAdminGameLibrary
{
    private function buildAdminGameLibrary(
        GameRepositoryInterface $repository,
        RunnerGatewayInterface $runner,
        ?ApworldVersionChecker $checker = null,
        ?SubmitApworldCandidate $submit = null,
        ?InMemoryApworldCandidateRepository $candidates = null,
    ): AdminGameLibrary {
        $usage = self::createStub(GameUsageCounterInterface::class);
        $usage->method('count')->willReturn(0);
        $normalizer = new InstallStepsNormalizer();

        return new AdminGameLibrary(
            $repository,
            self::createStub(AdminGameListQueryInterface::class),
            new NullLogger(),
            $runner,
            new MockClock(),
            $checker ?? new ApworldVersionChecker(new MockHttpClient([]), new NullLogger(), 'token'),
            $usage,
            new GamePlatformResolver(self::createStub(IgdbHttpClientInterface::class), new NullLogger()),
            $normalizer,
            new GameTutorialSeeder(self::createStub(GameCatalogLinksProviderInterface::class), $normalizer),
            new InstallStepsReader(),
            $submit ?? new SubmitApworldCandidate(self::createStub(GameRepositoryInterface::class), new InMemoryApworldCandidateRepository(), self::createStub(RunnerGatewayInterface::class), self::createStub(MinioStorageInterface::class), new MockClock(), new NullLogger(), 'apworlds'),
            $candidates ?? new InMemoryApworldCandidateRepository(),
        );
    }
}
