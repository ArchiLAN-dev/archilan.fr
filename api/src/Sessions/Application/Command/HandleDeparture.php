<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Support\DepartureDesk;
use App\Sessions\Domain\Entity\DepartureRequest;
use App\Sessions\Domain\Repository\DepartureRequestRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use Psr\Clock\ClockInterface;

/**
 * Story 52.6: a decider handles a departure - releases the player's world through the bridge (the feed announces
 * it as any release), or dismisses it (« il revient »). A bridge that does not answer leaves the request open.
 */
final readonly class HandleDeparture
{
    public function __construct(
        private DepartureDesk $desk,
        private DepartureRequestRepositoryInterface $requests,
        private SendBridgeCommand $command,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException           when the request is unknown in this session
     * @throws ForbiddenException          when the actor may not decide
     * @throws ConflictException           when the request is already closed, or the session not running
     * @throws ServiceUnavailableException when the bridge cannot release: the request stays open
     */
    public function release(string $actorId, bool $isAdmin, string $sessionId, string $requestId): void
    {
        $request = $this->openRequest($actorId, $isAdmin, $sessionId, $requestId);
        // The server reads the rest of the line as the player name: a name with spaces needs no quoting.
        $this->command->execute($sessionId, '!admin /release '.$request->getSlotName(), $actorId);
        $request->markReleased($actorId, $this->clock->now());
        $this->requests->flush();
    }

    /**
     * @throws NotFoundException  when the request is unknown in this session
     * @throws ForbiddenException when the actor may not decide
     * @throws ConflictException  when the request is already closed
     */
    public function dismiss(string $actorId, bool $isAdmin, string $sessionId, string $requestId): void
    {
        $request = $this->openRequest($actorId, $isAdmin, $sessionId, $requestId);
        $request->dismiss($actorId, $this->clock->now());
        $this->requests->flush();
    }

    private function openRequest(string $actorId, bool $isAdmin, string $sessionId, string $requestId): DepartureRequest
    {
        $context = $this->desk->context($sessionId);
        $request = $this->requests->findById($requestId);
        if (null === $context || !$request instanceof DepartureRequest || $request->getSessionId() !== $sessionId) {
            throw new NotFoundException('Départ introuvable.');
        }
        if (!$this->desk->canDecide($context, $actorId, $isAdmin)) {
            throw new ForbiddenException('Seuls les admins (le propriétaire en partie privée) traitent un départ.');
        }
        if (!$request->isOpen()) {
            throw new ConflictException('Ce départ est déjà traité.', 'departure_closed');
        }

        return $request;
    }
}
