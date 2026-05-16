<?php

declare(strict_types=1);

namespace App\Events\Application;

use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\Identity\Application\ValidationErrors;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminEventGameSelection
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the enriched game selection config for the event, or null if the event does not exist.
     *
     * @return array{gameSelectionEnabled: bool, gameSelectionMax: int|null, selectedGames: list<array{gameId: string, gameName: string, gameSlug: string}>, availableGames: list<array{id: string, name: string, slug: string, availability: string, isApworldReady: bool, coverImageUrl: string|null}>}|null
     */
    public function getConfig(string $eventId): ?array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        /** @var list<Game> $allGames */
        $allGames = $this->entityManager->getRepository(Game::class)->findBy([], ['name' => 'ASC'], 500);

        $gamesById = [];
        foreach ($allGames as $game) {
            $gamesById[$game->getId()] = $game;
        }

        $selectedGames = [];
        foreach ($event->getGameSelectionConfig() as $entry) {
            $game = $gamesById[$entry['gameId']] ?? null;
            if (!$game instanceof Game) {
                continue;
            }

            $selectedGames[] = [
                'gameId' => $game->getId(),
                'gameName' => $game->getName(),
                'gameSlug' => $game->getSlug(),
            ];
        }

        $availableGames = array_map(
            fn (Game $game): array => [
                'id' => $game->getId(),
                'name' => $game->getName(),
                'slug' => $game->getSlug(),
                'availability' => $game->getAvailability(),
                'isApworldReady' => $game->isApworldReady(),
                'coverImageUrl' => $game->getCoverImageUrl(),
            ],
            array_values(array_filter(
                $allGames,
                fn (Game $game): bool => $this->isGameAvailable($game),
            )),
        );

        return [
            'gameSelectionEnabled' => $event->isGameSelectionEnabled(),
            'gameSelectionMax' => $event->getGameSelectionMaxPerRegistrant(),
            'selectedGames' => $selectedGames,
            'availableGames' => $availableGames,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, errors: array<string, list<string>>}
     */
    public function configure(string $eventId, array $input): array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return ['found' => false, 'errors' => []];
        }

        $parsed = $this->parse($input);
        $errors = $this->validate($parsed, $event);

        if ([] !== $errors) {
            return ['found' => true, 'errors' => $errors];
        }

        $enabled = $parsed['gameSelectionEnabled'];
        if (null === $enabled) {
            return ['found' => true, 'errors' => ['gameSelectionEnabled' => ['Le champ gameSelectionEnabled est requis (booléen).']]];
        }

        $event->configureGameSelection(
            $enabled,
            $parsed['games'],
            new \DateTimeImmutable(),
            $parsed['gameSelectionMax'],
        );
        $this->entityManager->flush();

        $this->logger->info('event.game_selection_configured', ['eventId' => $eventId, 'enabled' => $enabled, 'gameCount' => count($parsed['games'])]);

        return ['found' => true, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{gameSelectionEnabled: bool|null, gameSelectionMax: int|null, games: list<array{gameId: string}>}
     */
    private function parse(array $input): array
    {
        $games = [];
        if (is_array($input['games'] ?? null)) {
            foreach ($input['games'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $games[] = [
                    'gameId' => is_string($entry['gameId'] ?? null) ? trim($entry['gameId']) : '',
                ];
            }
        }

        $gameSelectionMax = $input['gameSelectionMax'] ?? null;

        return [
            'gameSelectionEnabled' => is_bool($input['gameSelectionEnabled'] ?? null) ? $input['gameSelectionEnabled'] : null,
            'gameSelectionMax' => is_int($gameSelectionMax) && $gameSelectionMax > 0 ? $gameSelectionMax : null,
            'games' => $games,
        ];
    }

    /**
     * @param array{gameSelectionEnabled: bool|null, gameSelectionMax: int|null, games: list<array{gameId: string}>} $parsed
     *
     * @return array<string, list<string>>
     */
    private function validate(array $parsed, Event $event): array
    {
        $errors = new ValidationErrors();

        if (null === $parsed['gameSelectionEnabled']) {
            $errors->add('gameSelectionEnabled', 'Le champ gameSelectionEnabled est requis (booléen).');

            return $errors->toArray();
        }

        $seenGameIds = [];
        foreach ($parsed['games'] as $index => $entry) {
            $prefix = sprintf('games.%d', $index);

            if ('' === $entry['gameId']) {
                $errors->add($prefix.'.gameId', "L'identifiant du jeu est requis.");
                continue;
            }

            if (in_array($entry['gameId'], $seenGameIds, true)) {
                $errors->add($prefix.'.gameId', 'Ce jeu est déjà sélectionné.');
                continue;
            }
            $seenGameIds[] = $entry['gameId'];

            $game = $this->entityManager->find(Game::class, $entry['gameId']);

            if (!$game instanceof Game) {
                $errors->add($prefix.'.gameId', 'Jeu introuvable dans la bibliothèque.');
                continue;
            }

            if (!$this->isGameAvailable($game)) {
                $errors->add($prefix.'.gameId', 'Ce jeu n\'est pas disponible.');
                continue;
            }
        }

        return $errors->toArray();
    }

    private function isGameAvailable(Game $game): bool
    {
        return in_array($game->getAvailability(), [
            Game::AVAILABILITY_AVAILABLE,
            Game::AVAILABILITY_EXPERIMENTAL,
        ], true);
    }
}
