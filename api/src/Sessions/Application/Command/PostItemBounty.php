<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Query\SessionQuery;
use App\Sessions\Application\Support\PelleHintTerms;
use App\Sessions\Domain\Entity\ItemBounty;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\ItemBountyRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use Psr\Clock\ClockInterface;

/**
 * A player offers gold pelles to whoever sends them an item (story 41.4). The pelles are held at once: the
 * escrow line and the bounty are written in the same transaction, so there is never a bounty without its pelles.
 *
 * The caller has already checked that the player holds the slot.
 */
final readonly class PostItemBounty
{
    private const int MAX_REQUEST_ID_LENGTH = 40;

    public function __construct(
        private PelleHintTerms $terms,
        private SessionQuery $sessionQuery,
        private ItemBountyRepositoryInterface $bounties,
        private RecordPelleMovement $record,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException   when the session or the slot is unknown
     * @throws ConflictException   when the session is not running, or the slot already offers a bounty for the item
     * @throws ForbiddenException  when the session has no bounties, or the member is banned
     * @throws ValidationException when the request is malformed or the member cannot pay
     */
    public function post(string $userId, string $sessionId, int $slotIndex, string $itemName, int $amount, string $requestId): PostedItemBounty
    {
        $terms = $this->terms->of($sessionId);
        if (null === $terms) {
            throw new NotFoundException('Session introuvable.', 'not_found');
        }
        if (Session::STATUS_RUNNING !== $terms['session']->getStatus()) {
            throw new ConflictException('La session n\'est pas en cours.', 'session_not_running');
        }
        if (!$terms['bounties']) {
            throw new ForbiddenException('Cette partie n\'a pas de primes en pelles.', 'pelle_bounties_disabled');
        }
        if ('' === $requestId || \strlen($requestId) > self::MAX_REQUEST_ID_LENGTH) {
            throw new ValidationException('Identifiant de requête manquant.', [], 'invalid_request_id');
        }
        $slotName = $this->sessionQuery->archipelagoSlotName($sessionId, $slotIndex);
        if (null === $slotName) {
            throw new NotFoundException('Slot introuvable.', 'slot_not_found');
        }

        try {
            $bounty = ItemBounty::post($sessionId, $slotName, $itemName, $amount, $userId, $this->clock->now());
        } catch (\DomainException $e) {
            throw new ValidationException(sprintf('Une prime va de %d à %d pelles, sur un objet nommé.', ItemBounty::MIN_AMOUNT, ItemBounty::MAX_AMOUNT), [], $e->getMessage());
        }

        $existing = $this->bounties->findOpenFor($sessionId, $slotName, $bounty->getItemName());
        if ($existing instanceof ItemBounty) {
            if ($existing->getPosterId() === $userId) {
                // A double submit lands on the bounty it already posted.
                return PostedItemBounty::of($existing);
            }

            throw new ConflictException('Une prime est déjà posée sur cet objet.', 'bounty_exists');
        }

        $this->record->record(
            new RecordPelleMovementInput(
                $userId, -$amount, PelleKind::Gold, null, PelleReason::BountyEscrow,
                sprintf('Prime : %s', $bounty->getItemName()), null,
                sprintf('bounty:%s:%s', $userId, $requestId),
            ),
            fn () => $this->bounties->save($bounty),
        );

        return PostedItemBounty::of($bounty);
    }
}
