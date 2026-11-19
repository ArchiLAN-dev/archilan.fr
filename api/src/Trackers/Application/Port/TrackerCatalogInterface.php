<?php

declare(strict_types=1);

namespace App\Trackers\Application\Port;

/**
 * The games and their community catalog links (story 54.12), read in the contexts that own them. A catalog that
 * cannot be read gives no links, never an error.
 */
interface TrackerCatalogInterface
{
    /** @return list<array{label: string, url: ?string}>|null the game's catalog links, null for an unknown game */
    public function linksForGame(string $gameId): ?array;

    /** @return list<array{gameId: string, gameName: string, gameSlug: string, links: list<array{label: string, url: ?string}>}> every game, by name */
    public function allGames(): array;
}
