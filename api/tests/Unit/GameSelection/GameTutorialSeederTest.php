<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\GameCatalogLinksProviderInterface;
use App\GameSelection\Application\GameTutorialSeeder;
use App\GameSelection\Application\InstallStepsNormalizer;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
use PHPUnit\Framework\TestCase;

final class GameTutorialSeederTest extends TestCase
{
    public function testBundledGameYieldsIncludedNoteThenYamlAndConnect(): void
    {
        $game = $this->makeGame();
        new GameCatalogSync($game, bundledWithAp: true);

        $steps = $this->seeder([])->buildFor($game);

        self::assertSame(['note', 'yaml', 'connect'], array_map(static fn (array $s): string => $s['type'], $steps));
        self::assertSame('Rien à installer', $steps[0]['title']);
    }

    public function testApworldGameFoldsSourceUrlAndProviderLinks(): void
    {
        $game = $this->makeGame();
        $sync = new GameCatalogSync($game);
        $sync->update(null, 'https://github.com/owner/repo', null, null);

        $steps = $this->seeder([['label' => 'Guide', 'url' => 'https://example.org/guide']])->buildFor($game);

        self::assertSame('apworld', $steps[0]['type']);
        $labels = array_map(static fn (array $l): string => $l['label'], $steps[0]['links']);
        self::assertSame(["Source de l'apworld", 'Guide'], $labels);
        self::assertSame('https://github.com/owner/repo', $steps[0]['links'][0]['url']);
    }

    /**
     * @param list<array{label: string, url: string|null}> $providerLinks
     */
    private function seeder(array $providerLinks): GameTutorialSeeder
    {
        $provider = $this->createStub(GameCatalogLinksProviderInterface::class);
        $provider->method('linksFor')->willReturn($providerLinks);

        return new GameTutorialSeeder($provider, new InstallStepsNormalizer());
    }

    private function makeGame(): Game
    {
        return Game::create('Hollow Knight', 'hollow-knight', 'desc', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
    }
}
