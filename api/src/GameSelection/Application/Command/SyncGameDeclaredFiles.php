<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Copies onto each game the files its world declares (story 44.3): from the introspection of its served
 * apworld, or, for a world shipped with the Archipelago image, from the image's own listing by game name.
 * A file a failed generation proved necessary keeps its flag (`Game::recordDeclaredRequiredFiles`).
 */
final readonly class SyncGameDeclaredFiles
{
    public function __construct(
        private GameRepositoryInterface $games,
        private RunnerGatewayInterface $runner,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The whole catalogue: nightly, and on demand after a new image. `$reintrospect` first re-reads each
     * served apworld: an introspection made before story 44.3 carries no declared file. Slow - one
     * throwaway container per apworld - so a one-off after deployment, never the nightly run.
     */
    public function syncAll(bool $reintrospect = false): DeclaredFilesSyncReport
    {
        $official = $this->runner->fetchOfficialDeclaredFiles();
        if (null === $official) {
            // An orchestrator that does not answer would read as "no apworld declares anything" and empty
            // every game: nothing is touched until it answers again.
            $this->logger->warning('game.declared_files_sync_skipped_runner_unavailable');

            return new DeclaredFilesSyncReport(0, 0, false);
        }

        $updated = 0;
        $skipped = 0;
        foreach ($this->games->findAllSortedByName() as $game) {
            $hash = $game->getApworldHash();
            if ($reintrospect && null !== $hash && '' !== $hash && !$this->runner->reintrospectApworld($hash)) {
                $this->logger->warning('game.declared_files_reintrospection_failed', ['gameId' => $game->getId(), 'hash' => $hash]);
            }
            $declared = $this->declaredFor($game, $official);
            if (null === $declared) {
                ++$skipped;
                continue;
            }
            $before = $game->getRequiredFiles();
            $game->recordDeclaredRequiredFiles($declared);
            if ($before !== $game->getRequiredFiles()) {
                $this->games->save($game);
                ++$updated;
            }
        }

        $this->logger->info('game.declared_files_synced', ['updated' => $updated, 'skipped' => $skipped]);

        return new DeclaredFilesSyncReport($updated, $skipped, true);
    }

    /**
     * Null when nothing reliable is known - a game with neither apworld nor Archipelago name, or an image
     * that listed nothing at all: the current rows then stay as they are.
     *
     * @param array<string, list<array{setting: string, file: string, extension: string, description: ?string, md5s: list<string>}>> $official
     *
     * @return list<array{setting: string, file: string, extension: string, description: ?string, md5s: list<string>}>|null
     */
    private function declaredFor(Game $game, array $official): ?array
    {
        $hash = $game->getApworldHash();
        if (null !== $hash && '' !== $hash) {
            return $this->runner->fetchDeclaredFiles($hash);
        }

        $name = $game->getArchipelagoGameName();
        if (null === $name || '' === $name) {
            return null;
        }
        if ([] === $official) {
            return null;
        }

        return $official[$name] ?? [];
    }
}
