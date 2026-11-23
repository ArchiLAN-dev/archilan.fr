<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

use App\GameSelection\Domain\Entity\SizeSearch;
use App\GameSelection\Domain\Repository\SizeSearchRepositoryInterface;

/**
 * Story 56.7: where a player's size search stands. Someone else's search, or one for another game, reads as unknown.
 */
final readonly class SizeSearchQuery
{
    public function __construct(private SizeSearchRepositoryInterface $searches)
    {
    }

    /**
     * @return array{id: string, status: string, targetHours: float, maxTries: int, running: bool, tries: list<array{option: string, value: int, hours: float|null}>, proposal: array{option: string, from: int, value: int, hours: float}|null, error: string|null}|null
     */
    public function forPlayer(string $userId, string $gameId, string $searchId): ?array
    {
        $search = $this->searches->findById($searchId);
        if (!$search instanceof SizeSearch || $search->getUserId() !== $userId || $search->getGameId() !== $gameId) {
            return null;
        }
        $proposal = $search->getProposal();

        return [
            'id' => $search->getId(),
            'status' => $search->getStatus(),
            'targetHours' => round($search->getTargetHours(), 1),
            'maxTries' => SizeSearch::MAX_TRIES,
            'running' => null !== $search->getPending(),
            'tries' => array_map(
                static fn (array $t): array => ['option' => $t['option'], 'value' => $t['value'], 'hours' => null === $t['hours'] ? null : round($t['hours'], 1)],
                $search->getTries(),
            ),
            'proposal' => null === $proposal ? null : [...$proposal, 'hours' => round($proposal['hours'], 1)],
            'error' => $search->getError(),
        ];
    }
}
