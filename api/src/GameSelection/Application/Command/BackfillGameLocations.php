<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Support\ApworldIntrospectionNormalizer;
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

        foreach ($this->games->findAllSortedByName() as $game) {
            $hash = $game->getApworldHash();
            if (null === $hash || '' === $hash) {
                continue;
            }

            ++$processed;
            $locations = $this->runner->fetchLocationNames($hash);
            $items = ApworldIntrospectionNormalizer::items($this->runner->fetchItems($hash));
            if ([] === $locations && [] === $items['items']) {
                $this->logger->info('game.location_names_backfill_empty', ['gameId' => $game->getId(), 'hash' => $hash]);
                continue;
            }

            if ([] !== $locations) {
                $game->recordLocationNames($locations);
            }
            if ([] !== $items['items']) {
                $game->recordItemNames($items['items'], $items['groups']);
            }
            $this->games->save($game);
            ++$updated;
        }

        return new GameBackfillReport($processed, $updated);
    }
}
