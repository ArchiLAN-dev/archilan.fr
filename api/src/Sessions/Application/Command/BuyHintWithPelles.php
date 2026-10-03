<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Port\PelleHintGatewayInterface;
use App\Sessions\Application\Support\PelleHintTerms;
use App\Sessions\Domain\Entity\Session;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * A player buys a hint with pelles (story 41.3): the pelles are debited, then the bridge gives the hint for free
 * in Archipelago points; if the hint fails, the pelles come back as a refund line. The event's pelles pay first
 * on an event session, gold pelles otherwise.
 *
 * The caller has already checked that the player holds the slot (the same rule as buying a hint with points).
 */
final readonly class BuyHintWithPelles
{
    private const int MAX_REQUEST_ID_LENGTH = 40;

    public function __construct(
        private PelleHintTerms $terms,
        private PelleMovementRepositoryInterface $movements,
        private RecordPelleMovement $record,
        private PelleHintGatewayInterface $gateway,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param 'item'|'location' $kind
     *
     * @throws NotFoundException   when the session does not exist
     * @throws ConflictException   when the session is not running, or the hint failed (pelles refunded)
     * @throws ForbiddenException  when the session does not sell hints for pelles, or the member is banned
     * @throws ValidationException when the request is malformed or the member cannot pay
     */
    public function buy(string $userId, string $sessionId, int $slotIndex, string $kind, ?string $itemName, ?int $locationId, string $requestId): PelleHintPurchase
    {
        $terms = $this->terms->of($sessionId);
        if (null === $terms) {
            throw new NotFoundException('Session introuvable.', 'not_found');
        }
        if (Session::STATUS_RUNNING !== $terms['session']->getStatus()) {
            throw new ConflictException('La session n\'est pas en cours.', 'session_not_running');
        }
        if (!$terms['enabled']) {
            throw new ForbiddenException('Cette partie ne vend pas de hints contre des pelles.', 'pelle_hints_disabled');
        }
        if ('' === $requestId || \strlen($requestId) > self::MAX_REQUEST_ID_LENGTH) {
            throw new ValidationException('Identifiant de requête manquant.', [], 'invalid_request_id');
        }

        if ('item' === $kind) {
            $itemName = null === $itemName ? '' : trim($itemName);
            if ('' === $itemName) {
                throw new ValidationException('Quel objet ?', ['itemName' => ['Objet requis.']], 'validation_error');
            }
            $price = $terms['itemPrice'];
            $label = sprintf('Hint : %s', $itemName);
            $give = fn () => $this->gateway->hintItem($sessionId, $slotIndex, $itemName);
        } else {
            if (null === $locationId || $locationId < 0) {
                throw new ValidationException('Quel lieu ?', ['locationId' => ['Lieu requis.']], 'validation_error');
            }
            $price = $terms['locationPrice'];
            $label = sprintf('Hint de lieu : #%d', $locationId);
            $give = fn () => $this->gateway->hintLocation($sessionId, $slotIndex, $locationId);
        }

        [$purse, $eventId] = $this->purse($userId, $terms['eventId'], $price);

        $debit = $this->record->record(new RecordPelleMovementInput(
            $userId, -$price, $purse, $eventId, PelleReason::HintPurchase, $label, null,
            sprintf('hint:%s:%s', $userId, $requestId),
        ));
        if ($debit->alreadyRecorded) {
            // A double submit: paid once, hinted once.
            return new PelleHintPurchase($purse->value, $price, $debit->balanceAfter, true);
        }

        try {
            $give();
        } catch (\Throwable $e) {
            $this->logger->warning('sessions.pelle_hint_failed', ['sessionId' => $sessionId, 'slot' => $slotIndex, 'error' => $e->getMessage()]);
            $this->record->record(new RecordPelleMovementInput(
                $userId, $price, $purse, $eventId, PelleReason::HintRefund, 'Remboursement, '.lcfirst($label), null,
                sprintf('hint-refund:%s:%s', $userId, $requestId),
                byAdmin: true,
            ));

            throw new ConflictException('Le hint n\'a pas pu être donné ; tes pelles t\'ont été rendues.', 'hint_failed');
        }

        return new PelleHintPurchase($purse->value, $price, $debit->balanceAfter, false);
    }

    /**
     * The event's pelles first when they cover the price, then gold pelles.
     *
     * @return array{0: PelleKind, 1: string|null}
     */
    private function purse(string $userId, ?string $eventId, int $price): array
    {
        $event = null === $eventId ? 0 : $this->movements->balance($userId, PelleKind::Event, $eventId);
        if (null !== $eventId && $event >= $price) {
            return [PelleKind::Event, $eventId];
        }
        $gold = $this->movements->balance($userId, PelleKind::Gold, null);
        if ($gold >= $price) {
            return [PelleKind::Gold, null];
        }

        throw new ValidationException(sprintf('Il te faut %d pelles pour ce hint.', $price), ['price' => $price, 'event' => null === $eventId ? null : $event, 'gold' => $gold], 'insufficient_pelles');
    }
}
