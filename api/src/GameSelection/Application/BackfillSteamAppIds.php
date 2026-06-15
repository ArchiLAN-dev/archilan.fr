<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\GameRepositoryInterface;
use App\GameSelection\Infrastructure\IgdbHttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves and stores the Steam appid (from IGDB external_games) for every catalog game
 * that has an igdbId but no steamAppId yet, enabling exact-appid Steam library coupling
 * (epic 28). Per-game IGDB failures are logged and skipped; the run continues.
 */
final readonly class BackfillSteamAppIds
{
    public function __construct(
        private GameRepositoryInterface $games,
        private IgdbHttpClientInterface $igdb,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{processed: int, updated: int}
     */
    public function run(): array
    {
        $processed = 0;
        $updated = 0;

        foreach ($this->games->findAllSortedByName() as $game) {
            $igdbId = $game->getIgdbId();
            if (null === $igdbId || null !== $game->getSteamAppId()) {
                continue;
            }

            ++$processed;

            try {
                $steamAppId = $this->igdb->fetchSteamAppId($igdbId);
            } catch (\Throwable $e) {
                $this->logger->warning('game.steam_app_id_backfill_failed', [
                    'gameId' => $game->getId(),
                    'igdbId' => $igdbId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (null === $steamAppId) {
                continue;
            }

            $game->recordSteamAppId($steamAppId);
            $this->games->save($game);
            ++$updated;
        }

        return ['processed' => $processed, 'updated' => $updated];
    }
}
