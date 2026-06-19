<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Infrastructure\IgdbHttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves and records a game's IGDB platforms on demand (caller persists). Used to keep
 * platforms in sync when a game's igdbId is set or changed from the admin, without waiting
 * for the bulk `app:games:backfill-platforms` pass. IGDB failures are logged and swallowed
 * so they never break the surrounding save.
 */
final readonly class GamePlatformResolver
{
    public function __construct(
        private IgdbHttpClientInterface $igdb,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool true when platforms were (re)resolved, false when the IGDB call failed
     */
    public function resolve(Game $game): bool
    {
        $igdbId = $game->getIgdbId();
        if (null === $igdbId) {
            $game->recordPlatforms(null);

            return true;
        }

        try {
            $platforms = $this->igdb->fetchPlatforms($igdbId);
        } catch (\Throwable $exception) {
            $this->logger->warning('game.platforms_resolve_failed', [
                'gameId' => $game->getId(),
                'igdbId' => $igdbId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $game->recordPlatforms([] === $platforms ? null : $platforms);

        return true;
    }
}
