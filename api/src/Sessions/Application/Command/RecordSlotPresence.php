<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SlotPlaySpan;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotPlaySpanRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Counts play time from the bridge's heartbeat (story 32.26).
 *
 * The heartbeat names the slots a game client holds right now (trackers and text clients left out by the
 * bridge). Each named slot extends its latest span, or opens one when the last sighting is older than
 * SlotPlaySpan::MAX_GAP_SECONDS. Only while the session runs, and only for slots of the session: a name the
 * session does not know is dropped.
 */
final readonly class RecordSlotPresence
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private SlotPlaySpanRepositoryInterface $spans,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $slotNames
     */
    public function record(string $sessionId, array $slotNames): void
    {
        if ([] === $slotNames) {
            return;
        }
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session || Session::STATUS_RUNNING !== $session->getStatus()) {
            return;
        }

        $known = [];
        foreach ($this->slots->findBySessionId($sessionId) as $slot) {
            $known[$slot->getSlotName()] = true;
        }

        $now = $this->clock->now();
        foreach (array_unique($slotNames) as $name) {
            if (!isset($known[$name])) {
                continue;
            }
            $latest = $this->spans->findLatest($sessionId, $name);
            if (null !== $latest && $latest->continuesAt($now)) {
                $latest->extendTo($now);
                continue;
            }
            $this->spans->add(SlotPlaySpan::open(bin2hex(random_bytes(16)), $sessionId, $name, $now));
        }

        $this->spans->flush();
    }
}
