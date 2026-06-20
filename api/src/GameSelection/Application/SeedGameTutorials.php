<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\GameRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Bulk-seeds install tutorials for the whole catalog (cold start, story 31.1): every game with no
 * authored steps gets a default draft. Idempotent (skips games that already have steps unless
 * forced); per-game failures are logged and skipped. Mirrors the platform backfill command.
 */
final readonly class SeedGameTutorials
{
    public function __construct(
        private GameRepositoryInterface $games,
        private GameTutorialSeeder $seeder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{processed: int, seeded: int}
     */
    public function run(bool $force): array
    {
        $processed = 0;
        $seeded = 0;

        foreach ($this->games->findAllSortedByName() as $game) {
            if (!$force && [] !== $game->getInstallSteps()) {
                continue;
            }

            ++$processed;

            try {
                $steps = $this->seeder->buildFor($game);
            } catch (\Throwable $exception) {
                $this->logger->warning('game.tutorial_seed_failed', [
                    'gameId' => $game->getId(),
                    'error' => $exception->getMessage(),
                ]);
                continue;
            }

            $game->setInstallSteps($steps);
            $this->games->save($game);
            ++$seeded;
        }

        return ['processed' => $processed, 'seeded' => $seeded];
    }
}
