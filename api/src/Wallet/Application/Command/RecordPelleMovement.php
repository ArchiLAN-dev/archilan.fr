<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Exception\InvalidPelleMovementException;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The only way pelles move (story 41.1). Every earning or spending path of epic 41 goes through here, so the
 * ledger rules hold everywhere: a balance never goes below zero (checked and written under a lock on the
 * member), a keyed movement is written once, a banned member only moves by an admin's hand.
 */
final readonly class RecordPelleMovement
{
    public function __construct(
        private PelleMovementRepositoryInterface $movements,
        private UserRepositoryInterface $users,
        private EventRepositoryInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param (\Closure(PelleMovement): void)|null $inTransaction a write that must commit with the movement,
     *                                                            such as an admin's audit line
     *
     * @throws NotFoundException   when the member or the event does not exist
     * @throws ForbiddenException  when the member is banned and the movement is not an admin's
     * @throws ValidationException when the movement is malformed or the balance would go below zero
     */
    public function record(RecordPelleMovementInput $input, ?\Closure $inTransaction = null): RecordedPelleMovement
    {
        $now = $this->clock->now();
        $member = $this->users->findById($input->userId);
        if (!$member instanceof User || $member->isDeleted()) {
            throw new NotFoundException('Membre introuvable.', 'member_not_found');
        }
        if (!$input->byAdmin && User::MOD_BANNED === $member->moderationStatus($now)) {
            throw new ForbiddenException('Ce compte est banni : il ne gagne ni ne dépense de pelles.', 'member_banned');
        }
        if (null !== $input->eventId && !$this->events->findById($input->eventId) instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }

        $this->movements->beginTransaction();
        try {
            $this->movements->lockMember($input->userId);
            $before = $this->movements->balance($input->userId, $input->kind, $input->eventId);

            $existing = null === $input->uniqueKey ? null : $this->movements->findByUniqueKey($input->uniqueKey);
            if ($existing instanceof PelleMovement) {
                $this->movements->commit();

                return new RecordedPelleMovement($existing->getId(), $before, $before, true);
            }

            $after = $before + $input->amount;
            if ($after < 0) {
                throw new ValidationException(sprintf('Solde insuffisant : %d pelle(s) disponible(s).', $before), ['available' => $before], 'insufficient_pelles');
            }

            try {
                $movement = PelleMovement::record($input->userId, $input->amount, $input->kind, $input->eventId, $input->reason, $input->label, $input->authorId, $input->uniqueKey, $now);
            } catch (InvalidPelleMovementException $e) {
                throw new ValidationException('Mouvement de pelles invalide.', [], $e->getMessage());
            }

            $this->movements->save($movement);
            if (null !== $inTransaction) {
                $inTransaction($movement);
            }
            $this->movements->commit();
        } catch (\Throwable $e) {
            $this->movements->rollBack();

            throw $e;
        }

        return new RecordedPelleMovement($movement->getId(), $before, $after, false);
    }
}
