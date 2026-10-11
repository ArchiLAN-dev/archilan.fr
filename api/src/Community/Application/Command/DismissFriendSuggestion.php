<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Domain\Entity\FriendSuggestionDismissal;
use App\Community\Domain\Repository\FriendSuggestionDismissalRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

/**
 * « Ignorer » on a friend suggestion (story 43.2): that member is never suggested to the user again. Ignoring
 * twice is a no-op.
 */
final readonly class DismissFriendSuggestion
{
    public function __construct(
        private CommunityUserDirectoryQueryInterface $directory,
        private FriendSuggestionDismissalRepositoryInterface $dismissals,
        private ClockInterface $clock,
    ) {
    }

    public function dismiss(string $userId, string $slug): DismissFriendSuggestionOutcome
    {
        $targetId = $this->directory->userIdForSlug($slug);
        if (null === $targetId || $targetId === $userId) {
            return DismissFriendSuggestionOutcome::NotFound;
        }
        if ($this->dismissals->exists($userId, $targetId)) {
            return DismissFriendSuggestionOutcome::Dismissed;
        }

        try {
            $this->dismissals->save(FriendSuggestionDismissal::create($userId, $targetId, $this->clock->now()));
        } catch (UniqueConstraintViolationException) {
            // A concurrent click dismissed it first - idempotent.
        }

        return DismissFriendSuggestionOutcome::Dismissed;
    }
}
