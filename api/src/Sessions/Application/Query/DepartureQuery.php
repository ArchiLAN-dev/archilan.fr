<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Application\Support\DepartureDesk;
use App\Sessions\Domain\Entity\DepartureRequest;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\DepartureRequestRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;

/**
 * Story 52.6: the departures - for the player, whether « Je pars » is possible and already said; for the deciders,
 * the departures to handle.
 */
final readonly class DepartureQuery
{
    public function __construct(
        private DepartureDesk $desk,
        private DepartureRequestRepositoryInterface $requests,
        private SessionSlotRepositoryInterface $slots,
    ) {
    }

    /**
     * `available`: the player may say they leave (an event or a private run, running, world not released).
     * `requestedAt`: their open request, if any.
     *
     * @return array{available: bool, requestedAt: ?\DateTimeImmutable}
     */
    public function forPlayer(string $sessionId, int $slotIndex): array
    {
        $context = $this->desk->context($sessionId);
        $slotName = $this->desk->slotNames($sessionId)[$slotIndex] ?? null;
        if (null === $context || null === $slotName || Session::STATUS_RUNNING !== $context->session->getStatus()) {
            return ['available' => false, 'requestedAt' => null];
        }
        if ($this->slots->findBySessionAndSlotName($sessionId, $slotName)?->isWasReleased() ?? false) {
            return ['available' => false, 'requestedAt' => null];
        }

        return ['available' => true, 'requestedAt' => $this->requests->findOpen($sessionId, $slotName)?->getCreatedAt()];
    }

    /**
     * @return list<DepartureRow> oldest first
     *
     * @throws NotFoundException  when the session has no departures (unknown, a weekly)
     * @throws ForbiddenException when the viewer may not decide
     */
    public function toHandle(string $sessionId, string $viewerId, bool $isAdmin): array
    {
        $context = $this->desk->context($sessionId);
        if (null === $context) {
            throw new NotFoundException('Session introuvable.');
        }
        if (!$this->desk->canDecide($context, $viewerId, $isAdmin)) {
            throw new ForbiddenException('Seuls les admins (le propriétaire en partie privée) voient les départs.');
        }

        return array_map(
            static fn (DepartureRequest $request): DepartureRow => new DepartureRow($request->getId(), $request->getSlotIndex(), $request->getSlotName(), $request->getOrigin(), $request->getCreatedAt()),
            $this->requests->findOpenBySession($sessionId),
        );
    }
}
