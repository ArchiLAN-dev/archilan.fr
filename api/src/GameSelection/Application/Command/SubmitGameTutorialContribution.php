<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameTutorialContribution;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\GameSelection\Domain\Repository\GameTutorialContributionRepositoryInterface;
use App\Identity\Application\Support\ValidationErrors;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Submits a community install-tutorial contribution (story 31.6): on an existing game (by slug) or a
 * not-yet-listed game (by proposed name) - exactly one. Steps are validated by the shared normalizer.
 * Always created `pending`; never applied here (moderation is 31.7).
 */
final readonly class SubmitGameTutorialContribution
{
    public const int MAX_PROPOSED_NAME = 160;

    public function __construct(
        private GameRepositoryInterface $games,
        private GameTutorialContributionRepositoryInterface $contributions,
        private InstallStepsNormalizer $normalizer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed> $rawSteps
     *
     * @return string the new contribution id
     *
     * @throws NotFoundException   when the target game does not exist or is unavailable
     * @throws ValidationException when the submission is invalid
     */
    public function submit(string $authorId, ?string $gameSlug, ?string $proposedGameName, array $rawSteps, ?string $message): string
    {
        $gameSlug = null !== $gameSlug ? trim($gameSlug) : '';
        $proposedGameName = null !== $proposedGameName ? trim($proposedGameName) : '';
        $hasSlug = '' !== $gameSlug;
        $hasName = '' !== $proposedGameName;

        $errors = new ValidationErrors();

        if ($hasSlug === $hasName) {
            $errors->add('target', 'Indique soit un jeu existant, soit un nom de jeu non listé (exactement un).');

            throw new ValidationException('La contribution contient des erreurs.', $errors->toArray());
        }

        if ($hasName && mb_strlen($proposedGameName) > self::MAX_PROPOSED_NAME) {
            $errors->add('proposedGameName', sprintf('Le nom ne doit pas dépasser %d caractères.', self::MAX_PROPOSED_NAME));

            throw new ValidationException('La contribution contient des erreurs.', $errors->toArray());
        }

        $stepResult = $this->normalizer->normalize($rawSteps);
        if ([] !== $stepResult['errors']) {
            throw new ValidationException('La contribution contient des erreurs.', ['steps' => $stepResult['errors']]);
        }
        if ([] === $stepResult['steps']) {
            $errors->add('steps', 'Ajoute au moins une étape.');

            throw new ValidationException('La contribution contient des erreurs.', $errors->toArray());
        }

        $now = $this->clock->now();
        $id = bin2hex(random_bytes(16));

        if ($hasSlug) {
            $game = $this->games->findBySlug($gameSlug);
            if (!$game instanceof Game || !in_array($game->getAvailability(), [Game::AVAILABILITY_AVAILABLE, Game::AVAILABILITY_EXPERIMENTAL], true)) {
                throw new NotFoundException('Jeu introuvable.');
            }

            if ($this->contributions->countPendingForGame($authorId, $game->getId()) > 0) {
                $errors->add('target', 'Tu as déjà une proposition en attente pour ce jeu.');

                throw new ValidationException('La contribution contient des erreurs.', $errors->toArray());
            }

            $contribution = GameTutorialContribution::submitForGame($id, $authorId, $game->getId(), $stepResult['steps'], $message, $now);
        } else {
            if ($this->contributions->countPendingForProposedName($authorId, $proposedGameName) > 0) {
                $errors->add('target', 'Tu as déjà une proposition en attente pour ce jeu.');

                throw new ValidationException('La contribution contient des erreurs.', $errors->toArray());
            }

            $contribution = GameTutorialContribution::submitForProposedName($id, $authorId, $proposedGameName, $stepResult['steps'], $message, $now);
        }

        $this->contributions->save($contribution);

        return $id;
    }
}
