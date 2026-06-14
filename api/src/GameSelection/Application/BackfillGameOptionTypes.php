<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\GameRepositoryInterface;
use App\Sessions\Application\RunnerGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backfills `Game.optionTypes` (authoritative range bounds) for games whose apworld was
 * uploaded before story 9.25, by re-fetching the introspected option types from the runner.
 */
final readonly class BackfillGameOptionTypes
{
    public function __construct(
        private GameRepositoryInterface $games,
        private RunnerGatewayInterface $runner,
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
            $hash = $game->getApworldHash();
            if (null === $hash || '' === $hash) {
                continue;
            }

            ++$processed;
            $types = $this->runner->fetchOptionTypes($hash);
            if ([] === $types) {
                $this->logger->info('game.option_types_backfill_empty', ['gameId' => $game->getId(), 'hash' => $hash]);
                continue;
            }

            $game->setOptionTypes($types);
            $this->games->save($game);
            ++$updated;
        }

        return ['processed' => $processed, 'updated' => $updated];
    }
}
