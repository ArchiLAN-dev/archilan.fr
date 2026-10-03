<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Command;

use App\Sessions\Application\Command\BuyHintWithPelles;
use App\Sessions\Application\Command\PelleHintPurchase;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\WeeklyRuns\Application\Support\WeeklyPelleHintTerms;

/**
 * A player buys a hint with gold pelles in their weekly attempt (story 41.8): the weekly's own conditions are
 * checked here, the sale is the sessions' one (story 41.3).
 */
final readonly class BuyWeeklyHintWithPelles
{
    public function __construct(
        private WeeklyPelleHintTerms $terms,
        private BuyHintWithPelles $sale,
    ) {
    }

    /**
     * @param 'item'|'location' $kind
     *
     * @throws NotFoundException   when the attempt does not exist
     * @throws ForbiddenException  when it is someone else's, or the weekly does not sell hints for pelles
     * @throws ConflictException   when the attempt is not launched or already finished, or the hint failed
     * @throws ValidationException when the request is malformed or the member cannot pay
     */
    public function buy(string $userId, bool $isAdmin, string $runId, string $entryId, int $slotIndex, string $kind, ?string $itemName, ?int $locationId, string $requestId): PelleHintPurchase
    {
        $terms = $this->terms->of($runId, $entryId, $userId, $isAdmin);
        if ('not_found' === $terms) {
            throw new NotFoundException('Tentative introuvable.', 'not_found');
        }
        if ('forbidden' === $terms) {
            throw new ForbiddenException('Accès refusé.', 'forbidden');
        }
        if (null === $terms['bridgeSessionId']) {
            throw new ConflictException("Cette tentative n'est pas lancée.", 'not_launched');
        }
        if (null !== $terms['entry']->getGoalReachedAt()) {
            throw new ConflictException('Cette tentative est terminée.', 'entry_finished');
        }
        if (!$terms['enabled']) {
            throw new ForbiddenException('Cette hebdo ne vend pas de hints contre des pelles.', 'pelle_hints_disabled');
        }

        // Gold only: a weekly is not an event.
        return $this->sale->sell($userId, $terms['bridgeSessionId'], $slotIndex, $kind, $itemName, $locationId, $requestId, $terms['itemPrice'], $terms['locationPrice'], null);
    }
}
