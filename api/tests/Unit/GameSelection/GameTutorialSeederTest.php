<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Port\GameCatalogLinksProviderInterface;
use App\GameSelection\Application\Support\GameTutorialSeeder;
use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameCatalogSync;
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
        // Links are markdown inside the description since story 31.11, no longer a parallel field.
        self::assertStringContainsString(
            '- [Jeux supportés par Archipelago](https://archipelago.gg/games)',
            $steps[0]['description'],
        );
    }

    public function testApworldGameFoldsSourceUrlAndProviderLinks(): void
    {
        $game = $this->makeGame();
        $sync = new GameCatalogSync($game);
        $sync->update(null, 'https://github.com/owner/repo', null, null);

        $steps = $this->seeder([['label' => 'Guide', 'url' => 'https://example.org/guide']])->buildFor($game);

        self::assertSame('apworld', $steps[0]['type']);
        $description = $steps[0]['description'];
        self::assertStringContainsString("- [Source de l'apworld](https://github.com/owner/repo)", $description);
        self::assertStringContainsString('- [Guide](https://example.org/guide)', $description);
        // Order is preserved: the apworld source comes before the catalogue links.
        self::assertLessThan(
            mb_strpos($description, '- [Guide]'),
            mb_strpos($description, "- [Source de l'apworld]"),
        );
    }

    /**
     * @param list<array{label: string, url: string|null}> $providerLinks
     */
    private function seeder(array $providerLinks): GameTutorialSeeder
    {
        $provider = self::createStub(GameCatalogLinksProviderInterface::class);
        $provider->method('linksFor')->willReturn($providerLinks);

        return new GameTutorialSeeder($provider, new InstallStepsNormalizer());
    }

    private function makeGame(): Game
    {
        return Game::create('Hollow Knight', 'hollow-knight', 'desc', null, 'alt', 'credit', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
    }
}
