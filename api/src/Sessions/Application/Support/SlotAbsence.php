<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\PersonalRuns\Domain\Entity\Run;
use App\Registrations\Application\Query\PresenceTimelineQueryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Entity\SlotPlaySpan;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotPlaySpanRepositoryInterface;
use App\Sessions\Domain\Service\SlotBlockRule;

/**
 * Story 52.3: which worlds of a session have an absent player, the only ones the Passeur may open.
 *
 * - Event: outside the windows the player declared (story 52.2) and no client on the slot. No window declared:
 *   there the whole event, never absent.
 * - Private run: no client on the slot for the delay (2 h until the owner can set it, story 52.5); a slot never
 *   played counts from the session's first launch.
 *
 * "A client on the slot" is the bridge's heartbeat (story 32.26): a slot seen within a play span's gap.
 */
final readonly class SlotAbsence
{
    public const int PRIVATE_DEFAULT_DELAY_SECONDS = 7200;

    public function __construct(
        private SessionPlayersSnapshotRepositoryInterface $snapshots,
        private SessionSlotRepositoryInterface $slots,
        private SlotPlaySpanRepositoryInterface $playSpans,
        private PresenceTimelineQueryInterface $presence,
    ) {
    }

    /**
     * @return list<int> the Archipelago slot numbers whose player is absent
     */
    public function absentSlots(Session $session, ?Run $run, \DateTimeImmutable $now): array
    {
        $registrations = [];
        foreach ($this->slots->findBySessionId($session->getId()) as $slot) {
            $registrations[$slot->getSlotName()] = $slot->getRegistrationId();
        }

        $absent = [];
        foreach ($this->slotNames($session->getId()) as $slotIndex => $slotName) {
            $lastSeen = $this->playSpans->findLatest($session->getId(), $slotName)?->getLastSeenAt();
            $isAbsent = null !== $run
                ? $this->absentFromRun($session, $lastSeen, $now)
                : $this->absentFromEvent($registrations[$slotName] ?? null, $lastSeen, $now);
            if ($isAbsent) {
                $absent[] = $slotIndex;
            }
        }

        return $absent;
    }

    private function absentFromRun(Session $session, ?\DateTimeImmutable $lastSeen, \DateTimeImmutable $now): bool
    {
        $since = $lastSeen ?? $session->getFirstLaunchedAt() ?? $session->getStartedAt();

        return null !== $since && $now->getTimestamp() - $since->getTimestamp() >= self::PRIVATE_DEFAULT_DELAY_SECONDS;
    }

    private function absentFromEvent(?string $registrationId, ?\DateTimeImmutable $lastSeen, \DateTimeImmutable $now): bool
    {
        if (null === $registrationId || self::connected($lastSeen, $now)) {
            return false;
        }

        return $this->presence->isOutsideWindows($registrationId, $now);
    }

    private static function connected(?\DateTimeImmutable $lastSeen, \DateTimeImmutable $now): bool
    {
        return null !== $lastSeen && $now->getTimestamp() - $lastSeen->getTimestamp() <= SlotPlaySpan::MAX_GAP_SECONDS;
    }

    /**
     * The players' slots, by Archipelago slot number, from the last players state the bridge pushed.
     *
     * @return array<int, string>
     */
    private function slotNames(string $sessionId): array
    {
        $snapshot = $this->snapshots->findBySessionId($sessionId);
        $slots = $snapshot instanceof SessionPlayersSnapshot ? ($snapshot->getPayload()['slots'] ?? null) : null;
        if (!is_array($slots)) {
            return [];
        }

        $names = [];
        foreach ($slots as $slotIndex => $slot) {
            $name = is_array($slot) ? ($slot['slot_name'] ?? null) : null;
            if (is_int($slotIndex) && is_string($name) && SlotBlockRule::isPlayerSlot($name)) {
                $names[$slotIndex] = $name;
            }
        }

        return $names;
    }
}
