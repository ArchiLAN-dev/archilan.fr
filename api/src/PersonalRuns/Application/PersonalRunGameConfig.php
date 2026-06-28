<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Application\ValidationErrors;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;

final readonly class PersonalRunGameConfig
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private GameRepositoryInterface $games,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, errorCode: string|null, errors: array<string, list<string>>}
     */
    public function configure(string $runId, string $callerId, array $input): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        // Once the run leaves draft the multiworld is generated/fixed (idle/active/... all included):
        // changing the game list would be a no-op since resume replays the existing session.
        if ($run->isLockedForEditing()) {
            return $this->result(found: true, blocked: true, blockReason: 'run_generated');
        }

        $parseResult = $this->parseGames($input);
        if ([] !== $parseResult['errors']) {
            return $this->result(found: true, errorCode: 'game_id_required', errors: $parseResult['errors']);
        }

        $games = $parseResult['games'];

        if ([] === $games) {
            return $this->result(found: true, errorCode: 'games_required', errors: [
                'games' => ['Au moins un jeu est requis.'],
            ]);
        }

        $errors = $this->validateGameIds($games);
        if ([] !== $errors) {
            return $this->result(found: true, errorCode: 'unknown_game', errors: $errors);
        }

        $run->configureGames($games, new \DateTimeImmutable());
        $this->runs->flush();

        return $this->result(found: true);
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

    /**
     * @param array<string, list<string>> $errors
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, errorCode: string|null, errors: array<string, list<string>>}
     */
    private function result(
        bool $found = false,
        bool $authorized = true,
        bool $blocked = false,
        ?string $blockReason = null,
        ?string $errorCode = null,
        array $errors = [],
    ): array {
        return [
            'found' => $found,
            'authorized' => $authorized,
            'blocked' => $blocked,
            'blockReason' => $blockReason,
            'errorCode' => $errorCode,
            'errors' => $errors,
        ];
    }
}
