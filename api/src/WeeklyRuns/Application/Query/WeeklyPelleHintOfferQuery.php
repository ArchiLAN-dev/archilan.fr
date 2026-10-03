<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use App\WeeklyRuns\Application\Support\WeeklyPelleHintTerms;

/**
 * What a player can buy in pelles on a weekly slot page (story 41.8): the same shape as on a session (story 41.3),
 * with gold pelles only.
 */
final readonly class WeeklyPelleHintOfferQuery
{
    public function __construct(
        private WeeklyPelleHintTerms $terms,
        private PelleMovementRepositoryInterface $movements,
    ) {
    }

    /**
     * @return array{enabled: bool, itemPrice: int, locationPrice: int, eventBalance: null, goldBalance: int}|'not_found'|'forbidden'
     */
    public function offer(string $userId, bool $isAdmin, string $runId, string $entryId): array|string
    {
        $terms = $this->terms->of($runId, $entryId, $userId, $isAdmin);
        if (is_string($terms)) {
            return $terms;
        }

        return [
            'enabled' => $terms['enabled'] && null !== $terms['bridgeSessionId'] && null === $terms['entry']->getGoalReachedAt(),
            'itemPrice' => $terms['itemPrice'],
            'locationPrice' => $terms['locationPrice'],
            'eventBalance' => null,
            'goldBalance' => $this->movements->balance($userId, PelleKind::Gold, null),
        ];
    }
}
