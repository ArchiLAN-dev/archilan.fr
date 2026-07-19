<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Support\GameTutorialSeeder;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
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

    public function run(bool $force): TutorialSeedReport
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

            $game->updateInstallSteps($steps);
            $this->games->save($game);
            ++$seeded;
        }

        return new TutorialSeedReport($processed, $seeded);
    }
}
