<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Support\DepartureDesk;
use App\Sessions\Domain\Entity\DepartureRequest;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\DepartureRequestRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use Psr\Clock\ClockInterface;

/**
 * Story 52.6: « Je pars » - the player tells the staff (the owner in a private run) they leave. Nothing is released
 * here: a decider does it from the departures to handle.
 */
final readonly class RequestDeparture
{
    public function __construct(
        private DepartureDesk $desk,
        private DepartureRequestRepositoryInterface $requests,
        private SessionSlotRepositoryInterface $slots,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException when the slot is unknown
     * @throws ConflictException when the session has no departures (a weekly), is not running, the world is already
     *                           released, or a request is already open
     */
    public function execute(string $sessionId, int $slotIndex): void
    {
        $context = $this->desk->context($sessionId);
        if (null === $context) {
            throw new ConflictException('Cette partie n\'a pas de départs à signaler.', 'departure_unavailable');
        }
        if (Session::STATUS_RUNNING !== $context->session->getStatus()) {
            throw new ConflictException('La session n\'est pas en cours.', 'session_not_running');
        }
        $slotName = $this->desk->slotNames($sessionId)[$slotIndex] ?? null;
        if (null === $slotName) {
            throw new NotFoundException('Slot introuvable.');
        }
        if ($this->slots->findBySessionAndSlotName($sessionId, $slotName)?->isWasReleased() ?? false) {
            throw new ConflictException('Ton monde est déjà libéré.', 'slot_released');
        }

        $request = $this->requests->open(new DepartureRequest(bin2hex(random_bytes(16)), $sessionId, $slotIndex, $slotName, DepartureRequest::ORIGIN_PLAYER, $this->clock->now()));
        if (null === $request) {
            throw new ConflictException('Ton départ est déjà signalé.', 'departure_already_requested');
        }

        $this->desk->tellDeciders($context, $request);
    }
}
