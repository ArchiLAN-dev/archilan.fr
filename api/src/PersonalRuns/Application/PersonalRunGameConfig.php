<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Application\ValidationErrors;
use App\PersonalRuns\Domain\PersonalRun;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PersonalRunGameConfig
{
    use EntityFinderTrait;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null, errorCode: string|null, errors: array<string, list<string>>}
     */
    public function configure(string $runId, string $callerId, array $input): array
    {
        try {
            $run = $this->findOrFail(PersonalRun::class, $runId);
        } catch (\RuntimeException) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        if (in_array($run->getStatus(), PersonalRun::ACTIVE_STATUSES, true)) {
            return $this->result(found: true, blocked: true, blockReason: 'run_active');
        }

        if (!in_array($run->getStatus(), [PersonalRun::STATUS_DRAFT, PersonalRun::STATUS_IDLE], true)) {
            return $this->result(found: true, blocked: true, blockReason: 'run_not_configurable');
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
        $this->entityManager->flush();

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
            $game = $this->entityManager->find(ArchipelagoGame::class, $entry['gameId']);

            if (!$game instanceof ArchipelagoGame) {
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
