<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backfills `Game.locationNames` (static apworld location list) for games whose apworld was uploaded
 * before story 4.14, by re-fetching the introspected location names from the runner.
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
            if ([] === $locations) {
                $this->logger->info('game.location_names_backfill_empty', ['gameId' => $game->getId(), 'hash' => $hash]);
                continue;
            }

            $game->recordLocationNames($locations);
            $this->games->save($game);
            ++$updated;
        }

        return new GameBackfillReport($processed, $updated);
    }
}
