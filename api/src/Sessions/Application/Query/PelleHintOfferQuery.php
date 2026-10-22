<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Support\PelleHintTerms;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;

/**
 * What a player can buy in pelles on a slot page (story 41.3): whether the session sells hints for pelles, the
 * prices, and the balances that can pay (the event's pelles on an event session, gold pelles everywhere).
 */
final readonly class PelleHintOfferQuery
{
    public function __construct(
        private PelleHintTerms $terms,
        private PelleMovementRepositoryInterface $movements,
    ) {
    }

    /**
     * @return array{enabled: bool, itemPrice: int, locationPrice: int, eventBalance: int|null, bronzeBalance: int|null, goldBalance: int}|null
     */
    public function offer(string $userId, string $sessionId): ?array
    {
        $terms = $this->terms->of($sessionId);
        if (null === $terms) {
            return null;
        }

        return [
            'enabled' => $terms['enabled'],
            'itemPrice' => $terms['itemPrice'],
            'locationPrice' => $terms['locationPrice'],
            'eventBalance' => null === $terms['eventId'] ? null : $this->movements->balance($userId, PelleKind::Event, $terms['eventId']),
            // Story 41.38: on a private run, the run's bronze is the only balance that pays.
            'bronzeBalance' => null === $terms['runId'] ? null : $this->movements->balance($userId, PelleKind::Bronze, null, $terms['runId']),
            'goldBalance' => $this->movements->balance($userId, PelleKind::Gold, null),
        ];
    }
}
