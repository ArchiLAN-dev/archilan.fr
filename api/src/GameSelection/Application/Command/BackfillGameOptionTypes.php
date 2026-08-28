<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Backfills `Game.optionTypes` from the runner's introspection.
 *
 * By default it only **re-reads** what the runner already holds - the sidecar written once, in the
 * background, when the apworld was uploaded. That is enough when the sidecar is current, and it was
 * the whole job until story 9.53.
 *
 * It is not enough after the introspection itself changes: the sidecar of an existing game was
 * written by the previous image and re-reading it returns the same old answer. `$reintrospect` asks
 * the runner to regenerate it first. It is opt-in because each one runs a container that loads all
 * of Archipelago, so a full catalogue sweep is long.
 */
final readonly class BackfillGameOptionTypes
{
    public function __construct(
        private GameRepositoryInterface $games,
        private RunnerGatewayInterface $runner,
        private LoggerInterface $logger,
    ) {
    }

    public function run(bool $reintrospect = false, ?string $gameSlug = null): OptionTypesBackfillReport
    {
        $processed = 0;
        $updated = 0;
        $reintrospected = 0;
        $reintrospectionFailed = 0;

        foreach ($this->gamesToSweep($gameSlug) as $game) {
            $hash = $game->getApworldHash();
            if (null === $hash || '' === $hash) {
                continue;
            }

            ++$processed;

            // Order matters: regenerate the sidecar, then read it. The other way round reads the
            // one written by the previous image and changes nothing, which is exactly the trap
            // this option exists to close.
            if ($reintrospect) {
                if ($this->runner->reintrospectApworld($hash)) {
                    ++$reintrospected;
                } else {
                    // The previous introspection is intact, so re-reading below is still worth
                    // doing: it just returns what it returned before.
                    ++$reintrospectionFailed;
                }
            }

            $types = $this->runner->fetchOptionTypes($hash);
            if ([] === $types) {
                $this->logger->info('game.option_types_backfill_empty', ['gameId' => $game->getId(), 'hash' => $hash]);
                continue;
            }

            $game->recordOptionTypes($types);
            $this->games->save($game);
            ++$updated;
        }

        return new OptionTypesBackfillReport($processed, $updated, $reintrospected, $reintrospectionFailed);
    }

    /**
     * The games to sweep: the whole catalogue, or the single one named by its slug.
     *
     * @return list<Game>
     */
    private function gamesToSweep(?string $gameSlug): array
    {
        if (null === $gameSlug || '' === $gameSlug) {
            return $this->games->findAllSortedByName();
        }

        $game = $this->games->findBySlug($gameSlug);

        return $game instanceof Game ? [$game] : [];
    }
}
