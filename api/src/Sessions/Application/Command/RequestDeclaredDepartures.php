<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Registrations\Application\Query\PresenceTimelineQueryInterface;
use App\Sessions\Application\Support\DepartureDesk;
use App\Sessions\Domain\Entity\DepartureRequest;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\DepartureRequestRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotPlaySpanRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Story 52.6: the scheduled pass - in a running event session, a member whose declared departure (the end of their
 * last window, story 52.2) has passed while their slot is offline gets the same request as « Je pars ». Once per
 * departure: a request made since then (open, released or dismissed as « il revient ») stops a new one.
 */
final readonly class RequestDeclaredDepartures
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private SlotPlaySpanRepositoryInterface $playSpans,
        private PresenceTimelineQueryInterface $presence,
        private DepartureRequestRepositoryInterface $requests,
        private DepartureDesk $desk,
        private ClockInterface $clock,
    ) {
    }

    /** @return int the requests opened */
    public function execute(): int
    {
        $now = $this->clock->now();
        $opened = 0;
        foreach ($this->sessions->findByStatus(Session::STATUS_RUNNING) as $session) {
            $context = $this->desk->context($session->getId());
            // Declared departures exist at events only; a private run has no windows.
            if (null === $context || null === $context->event) {
                continue;
            }
            $indexes = array_flip($this->desk->slotNames($session->getId()));
            foreach ($this->slots->findBySessionId($session->getId()) as $slot) {
                $index = $indexes[$slot->getSlotName()] ?? null;
                if (null === $index || !$this->hasLeft($slot, $now)) {
                    continue;
                }
                $request = $this->requests->open(new DepartureRequest(bin2hex(random_bytes(16)), $session->getId(), $index, $slot->getSlotName(), DepartureRequest::ORIGIN_SCHEDULE, $now));
                if (null !== $request) {
                    ++$opened;
                    $this->desk->tellDeciders($context, $request);
                }
            }
        }

        return $opened;
    }

    private function hasLeft(SessionSlot $slot, \DateTimeImmutable $now): bool
    {
        if ($slot->isWasReleased()) {
            return false;
        }
        $departure = $this->presence->declaredDepartureAt($slot->getRegistrationId());
        if (null === $departure || $departure > $now) {
            return false;
        }
        if ($this->playSpans->findLatest($slot->getSessionId(), $slot->getSlotName())?->continuesAt($now) ?? false) {
            return false;
        }

        return !$this->requests->existsSince($slot->getSessionId(), $slot->getSlotName(), $departure);
    }
}
