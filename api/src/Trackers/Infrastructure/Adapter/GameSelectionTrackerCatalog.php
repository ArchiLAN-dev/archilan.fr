<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Adapter;

use App\GameSelection\Application\Port\GameCatalogLinksProviderInterface;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Trackers\Application\Port\TrackerCatalogInterface;

/**
 * The games and their catalog links, from GameSelection (story 54.12). The links come from the synced sheet, read
 * once and cached: going through every game does not read it again.
 */
final readonly class GameSelectionTrackerCatalog implements TrackerCatalogInterface
{
    public function __construct(
        private GameRepositoryInterface $games,
        private GameCatalogLinksProviderInterface $catalogLinks,
    ) {
    }

    public function linksForGame(string $gameId): ?array
    {
        $game = $this->games->findById($gameId);

        return null === $game ? null : $this->linksOf($game);
    }

    public function allGames(): array
    {
        return array_map(fn (Game $game): array => [
            'gameId' => $game->getId(),
            'gameName' => $game->getName(),
            'gameSlug' => $game->getSlug(),
            'links' => $this->linksOf($game),
        ], $this->games->findAllSortedByName());
    }

    /** @return list<array{label: string, url: ?string}> */
    private function linksOf(Game $game): array
    {
        return $this->catalogLinks->linksFor($game->getCatalogSheetName(), $game->getArchipelagoGameName(), $game->getName());
    }
}
