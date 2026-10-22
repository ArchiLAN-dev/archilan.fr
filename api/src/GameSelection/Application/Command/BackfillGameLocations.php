<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Support\ApworldIntrospectionNormalizer;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backfills `Game.locationNames` (static apworld location list) for games whose apworld was uploaded
 * before story 4.14, by re-fetching the introspected location names from the runner. Story 9.58: the item
 * names and groups too, once the apworlds are reintrospected by an image that knows items.
 */
final readonly class BackfillGameLocations
{
    public function __construct(
        private GameRepositoryInterface $games,
        private RunnerGatewayInterface $runner,
        private LoggerInterface $logger,
    ) {
    }

    public function run(): GameBackfillReport
    {
        $processed = 0;
        $updated = 0;

        // One game at a time, released once saved (story 38.20): holding every game's names at once ran PHP out
        // of memory in prod.
        foreach ($this->games->findAllIdsSortedByName() as $gameId) {
            $game = $this->games->findById($gameId);
            if (!$game instanceof Game) {
                continue;
            }

            $hash = $game->getApworldHash();
            if (null !== $hash && '' !== $hash) {
                ++$processed;
                if ($this->backfill($game, $hash)) {
                    ++$updated;
                }
            }
            $this->games->release($game);
        }

        return new GameBackfillReport($processed, $updated);
    }

    /** Whether the game was updated. */
    private function backfill(Game $game, string $hash): bool
    {
        $locations = $this->runner->fetchLocationNames($hash);
        $items = ApworldIntrospectionNormalizer::items($this->runner->fetchItems($hash));
        if ([] === $locations && [] === $items['items']) {
            $this->logger->info('game.location_names_backfill_empty', ['gameId' => $game->getId(), 'hash' => $hash]);

            return false;
        }

        if ([] !== $locations) {
            $game->recordLocationNames($locations);
        }
        if ([] !== $items['items']) {
            $game->recordItemNames($items['items'], $items['groups']);
        }
        $this->games->save($game);

        return true;
    }
}
