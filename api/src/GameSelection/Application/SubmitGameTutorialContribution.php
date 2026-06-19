<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\GameSelection\Domain\GameTutorialContribution;
use App\GameSelection\Domain\GameTutorialContributionRepositoryInterface;
use App\Identity\Application\ValidationErrors;

/**
 * Submits a community install-tutorial contribution (story 31.6): on an existing game (by slug) or a
 * not-yet-listed game (by proposed name) - exactly one. Steps are validated by the shared normalizer.
 * Always created `pending`; never applied here (moderation is 31.7).
 */
final readonly class SubmitGameTutorialContribution
{
    public const MAX_PROPOSED_NAME = 160;

    public function __construct(
        private GameRepositoryInterface $games,
        private GameTutorialContributionRepositoryInterface $contributions,
        private InstallStepsNormalizer $normalizer,
    ) {
    }

    /**
     * @param array<mixed> $rawSteps
     *
     * @return array{found: bool, id?: string, errors: array<string, list<string>>}
     */
    public function submit(string $authorId, ?string $gameSlug, ?string $proposedGameName, array $rawSteps, ?string $message): array
    {
        $gameSlug = null !== $gameSlug ? trim($gameSlug) : '';
        $proposedGameName = null !== $proposedGameName ? trim($proposedGameName) : '';
        $hasSlug = '' !== $gameSlug;
        $hasName = '' !== $proposedGameName;

        $errors = new ValidationErrors();

        if ($hasSlug === $hasName) {
            $errors->add('target', 'Indique soit un jeu existant, soit un nom de jeu non listé (exactement un).');

            return ['found' => true, 'errors' => $errors->toArray()];
        }

        if ($hasName && mb_strlen($proposedGameName) > self::MAX_PROPOSED_NAME) {
            $errors->add('proposedGameName', sprintf('Le nom ne doit pas dépasser %d caractères.', self::MAX_PROPOSED_NAME));

            return ['found' => true, 'errors' => $errors->toArray()];
        }

        $stepResult = $this->normalizer->normalize($rawSteps);
        if ([] !== $stepResult['errors']) {
            return ['found' => true, 'errors' => ['steps' => $stepResult['errors']]];
        }
        if ([] === $stepResult['steps']) {
            $errors->add('steps', 'Ajoute au moins une étape.');

            return ['found' => true, 'errors' => $errors->toArray()];
        }

        $now = new \DateTimeImmutable();
        $id = bin2hex(random_bytes(16));

        if ($hasSlug) {
            $game = $this->games->findBySlug($gameSlug);
            if (!$game instanceof Game || !in_array($game->getAvailability(), [Game::AVAILABILITY_AVAILABLE, Game::AVAILABILITY_EXPERIMENTAL], true)) {
                return ['found' => false, 'errors' => []];
            }

            if ($this->contributions->countPendingForGame($authorId, $game->getId()) > 0) {
                $errors->add('target', 'Tu as déjà une proposition en attente pour ce jeu.');

                return ['found' => true, 'errors' => $errors->toArray()];
            }

            $contribution = GameTutorialContribution::submitForGame($id, $authorId, $game->getId(), $stepResult['steps'], $message, $now);
        } else {
            if ($this->contributions->countPendingForProposedName($authorId, $proposedGameName) > 0) {
                $errors->add('target', 'Tu as déjà une proposition en attente pour ce jeu.');

                return ['found' => true, 'errors' => $errors->toArray()];
            }

            $contribution = GameTutorialContribution::submitForProposedName($id, $authorId, $proposedGameName, $stepResult['steps'], $message, $now);
        }

        $this->contributions->save($contribution);

        return ['found' => true, 'id' => $id, 'errors' => []];
    }
}
