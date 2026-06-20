<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\GameCatalogLinksProviderInterface;
use App\GameSelection\Application\GameTutorialSeeder;
use App\GameSelection\Application\InstallStepsNormalizer;
use App\GameSelection\Application\SeedGameTutorials;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SeedGameTutorialsTest extends TestCase
{
    public function testSeedsOnlyGamesWithoutSteps(): void
    {
        $fresh = $this->makeGame('fresh');
        $authored = $this->makeGame('authored');
        $authored->setInstallSteps([['type' => 'note', 'title' => 'kept', 'description' => '', 'links' => []]]);

        $repo = $this->createMock(GameRepositoryInterface::class);
        $repo->method('findAllSortedByName')->willReturn([$fresh, $authored]);
        $repo->expects(self::once())->method('save')->with($fresh);

        $result = $this->service($repo)->run(false);

        self::assertSame(['processed' => 1, 'seeded' => 1], $result);
        self::assertNotSame([], $fresh->getInstallSteps());
        self::assertSame('kept', $authored->getInstallSteps()[0]['title']);
    }

    public function testForceReseedsAll(): void
    {
        $a = $this->makeGame('a');
        $b = $this->makeGame('b');
        $b->setInstallSteps([['type' => 'note', 'title' => 'old', 'description' => '', 'links' => []]]);

        $repo = $this->createMock(GameRepositoryInterface::class);
        $repo->method('findAllSortedByName')->willReturn([$a, $b]);
        $repo->expects(self::exactly(2))->method('save');

        $result = $this->service($repo)->run(true);

        self::assertSame(['processed' => 2, 'seeded' => 2], $result);
    }

    private function service(GameRepositoryInterface $repo): SeedGameTutorials
    {
        $provider = $this->createStub(GameCatalogLinksProviderInterface::class);
        $provider->method('linksFor')->willReturn([]);
        $seeder = new GameTutorialSeeder($provider, new InstallStepsNormalizer());

        return new SeedGameTutorials($repo, $seeder, new NullLogger());
    }

    private function makeGame(string $slug): Game
    {
        return Game::create('Game '.$slug, $slug, 'desc', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
    }
}
