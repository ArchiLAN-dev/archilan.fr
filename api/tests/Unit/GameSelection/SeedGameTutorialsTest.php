<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\SeedGameTutorials;
use App\GameSelection\Application\Port\GameCatalogLinksProviderInterface;
use App\GameSelection\Application\Support\GameTutorialSeeder;
use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SeedGameTutorialsTest extends TestCase
{
    public function testSeedsOnlyGamesWithoutSteps(): void
    {
        $fresh = $this->makeGame('fresh');
        $authored = $this->makeGame('authored');
        $authored->updateInstallSteps([['type' => 'note', 'title' => 'kept', 'description' => '', 'links' => []]]);

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
        $b->updateInstallSteps([['type' => 'note', 'title' => 'old', 'description' => '', 'links' => []]]);

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
