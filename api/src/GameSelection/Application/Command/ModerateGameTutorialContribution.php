<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\Community\Application\Support\Notifier;
use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameTutorialContribution;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\GameSelection\Domain\Repository\GameTutorialContributionRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Admin moderation of community tutorial contributions (story 31.7): approve (applies the steps to the
 * target game's install_steps in one unit of work) or reject (with a reason). The author is notified
 * post-commit; notification failures never undo the moderation. Only `pending` contributions move.
 */
final readonly class ModerateGameTutorialContribution
{
    public const NOTIFICATION_TYPE = 'tutorial_contribution_reviewed';

    public function __construct(
        private GameTutorialContributionRepositoryInterface $contributions,
        private GameRepositoryInterface $games,
        private InstallStepsNormalizer $normalizer,
        private Notifier $notifier,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<mixed>|null $overrideSteps moderator-edited steps to apply instead of the submitted ones
     *
     * @return array{found: bool, conflict?: bool, errors: array<string, list<string>>}
     */
    public function approve(string $id, string $reviewerId, ?array $overrideSteps): array
    {
        $contribution = $this->contributions->findById($id);
        if (null === $contribution) {
            return ['found' => false, 'errors' => []];
        }
        if (GameTutorialContribution::STATUS_PENDING !== $contribution->getStatus()) {
            return ['found' => true, 'conflict' => true, 'errors' => []];
        }

        $result = $this->normalizer->normalize($overrideSteps ?? $contribution->getSteps());
        if ([] !== $result['errors']) {
            return ['found' => true, 'errors' => ['steps' => $result['errors']]];
        }

        try {
            $contribution->approve($reviewerId, $this->clock->now());
        } catch (\DomainException) {
            // Lost a concurrent moderation race: the aggregate invariant rejects a non-pending transition.
            return ['found' => true, 'conflict' => true, 'errors' => []];
        }

        $gameId = $contribution->getGameId();
        if (null !== $gameId) {
            $game = $this->games->findById($gameId);
            if ($game instanceof Game) {
                // Replaces the whole tutorial; managed entity flushed together with the contribution below.
                $game->setInstallSteps($result['steps']);
            }
        }

        $this->contributions->save($contribution);

        $this->notifyAuthor($contribution, 'approved', null);

        return ['found' => true, 'errors' => []];
    }

    /**
     * @return array{found: bool, conflict?: bool, errors: array<string, list<string>>}
     */
    public function reject(string $id, string $reviewerId, string $reason): array
    {
        $contribution = $this->contributions->findById($id);
        if (null === $contribution) {
            return ['found' => false, 'errors' => []];
        }
        if (GameTutorialContribution::STATUS_PENDING !== $contribution->getStatus()) {
            return ['found' => true, 'conflict' => true, 'errors' => []];
        }
        if ('' === trim($reason)) {
            return ['found' => true, 'errors' => ['reason' => ['La raison du refus est requise.']]];
        }

        try {
            $contribution->reject($reviewerId, $reason, $this->clock->now());
        } catch (\DomainException) {
            return ['found' => true, 'conflict' => true, 'errors' => []];
        }

        $this->contributions->save($contribution);

        $this->notifyAuthor($contribution, 'rejected', trim($reason));

        return ['found' => true, 'errors' => []];
    }

    private function notifyAuthor(GameTutorialContribution $contribution, string $status, ?string $reason): void
    {
        try {
            $this->notifier->notify($contribution->getAuthorId(), self::NOTIFICATION_TYPE, [
                'status' => $status,
                'gameId' => $contribution->getGameId(),
                'proposedGameName' => $contribution->getProposedGameName(),
                'reason' => $reason,
            ]);
        } catch (\Throwable $exception) {
            $this->logger->warning('contribution.notify_failed', [
                'contributionId' => $contribution->getId(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
