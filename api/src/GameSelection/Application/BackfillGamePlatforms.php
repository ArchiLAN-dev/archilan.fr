<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\GameRepositoryInterface;
use App\GameSelection\Infrastructure\IgdbHttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves and stores the IGDB platforms for every catalog game that has an igdbId but no
 * platforms yet, enabling platform categories on the Jeux page (epic 28). Per-game IGDB
 * failures are logged and skipped; the run continues.
 */
final readonly class BackfillGamePlatforms
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
            if (null === $igdbId || null !== $game->getPlatforms()) {
                continue;
            }

            ++$processed;

            try {
                $platforms = $this->igdb->fetchPlatforms($igdbId);
            } catch (\Throwable $e) {
                $this->logger->warning('game.platforms_backfill_failed', [
                    'gameId' => $game->getId(),
                    'igdbId' => $igdbId,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ([] === $platforms) {
                continue;
            }

            $game->recordPlatforms($platforms);
            $this->games->save($game);
            ++$updated;
        }

        return ['processed' => $processed, 'updated' => $updated];
    }
}
