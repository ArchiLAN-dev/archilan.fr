<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Command;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Application\Support\ValidationErrors;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;

final readonly class PersonalRunGameConfig
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private GameRepositoryInterface $games,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws NotFoundException   when the run does not exist
     * @throws ForbiddenException  when the caller does not own the run
     * @throws ValidationException when the run is locked or the game list is invalid
     */
    public function configure(string $runId, string $callerId, array $input): void
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            throw new NotFoundException('Run introuvable.');
        }

        if (!$run->isOwnedBy($callerId)) {
            throw new ForbiddenException('Accès refusé.');
        }

        // Once the run leaves draft the multiworld is generated/fixed (idle/active/... all included):
        // changing the game list would be a no-op since resume replays the existing session.
        if ($run->isLockedForEditing()) {
            throw new ValidationException('Modification impossible dans l\'état actuel.', [], 'run_generated');
        }

        $parseResult = $this->parseGames($input);
        if ([] !== $parseResult['errors']) {
            throw new ValidationException('Configuration de jeux invalide.', $parseResult['errors'], 'game_id_required');
        }

        $games = $parseResult['games'];

        if ([] === $games) {
            throw new ValidationException('Configuration de jeux invalide.', ['games' => ['Au moins un jeu est requis.']], 'games_required');
        }

        $errors = $this->validateGameIds($games);
        if ([] !== $errors) {
            throw new ValidationException('Configuration de jeux invalide.', $errors, 'unknown_game');
        }

        $run->configureGames($games, $this->clock->now());
        $this->runs->flush();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{games: list<array{gameId: string}>, errors: array<string, list<string>>}
     */
    private function parseGames(array $input): array
    {
        $games = [];
        $errors = new ValidationErrors();
        $raw = $input['games'] ?? null;

        if (!is_array($raw)) {
            return ['games' => $games, 'errors' => []];
        }

        foreach ($raw as $index => $entry) {
            if (!is_array($entry)) {
                $errors->add(sprintf('games.%d.gameId', $index), 'Le jeu est requis.');
                continue;
            }
            $gameId = is_string($entry['gameId'] ?? null) ? trim($entry['gameId']) : '';
            if ('' !== $gameId) {
                $games[] = ['gameId' => $gameId];
                continue;
            }

            $errors->add(sprintf('games.%d.gameId', $index), 'Le jeu est requis.');
        }

        return ['games' => $games, 'errors' => $errors->toArray()];
    }

    /**
     * @param list<array{gameId: string}> $games
     *
     * @return array<string, list<string>>
     */
    private function validateGameIds(array $games): array
    {
        $errors = new ValidationErrors();

        foreach ($games as $index => $entry) {
            $game = $this->games->findById($entry['gameId']);

            if (!$game instanceof Game) {
                $errors->add(sprintf('games.%d.gameId', $index), 'Jeu introuvable dans la bibliothèque.');
            }
        }

        return $errors->toArray();
    }
}
