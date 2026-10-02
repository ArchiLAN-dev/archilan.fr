<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Service\SlotBlockTracker;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Service\SlotCheckActivity;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the last players state the bridge pushed for a session (story 17.21): one row per
 * session, overwritten on every push, so the Progression tab can show the last known state when
 * the bridge is gone (idle/stopped session, dead container).
 *
 * Story 40.1: the same push also follows the BK episodes of a private run's slots.
 * Story 30.45: and dates the slots whose checks grew since the previous push, for the "En jeu" presence.
 */
final readonly class RecordPlayersSnapshot
{
    public function __construct(
        private SessionPlayersSnapshotRepositoryInterface $snapshots,
        private ClockInterface $clock,
        private SlotBlockTracker $blockTracker,
        private SessionSlotRepositoryInterface $slots,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public function record(string $sessionId, array $payload): void
    {
        $existing = $this->snapshots->findBySessionId($sessionId);
        $previous = $existing?->getPayload();
        if (null !== $existing) {
            $existing->refresh($payload, $this->clock->now());
            $this->snapshots->save($existing);
        } else {
            $this->snapshots->save(new SessionPlayersSnapshot($sessionId, $payload, $this->clock->now()));
        }

        // The snapshot is saved first: a dating or BK tracking failure is logged and never costs it.
        try {
            $this->datePlayedSlots($sessionId, $previous, $payload);
        } catch (\Throwable $exception) {
            $this->logger->error('Dating the slots last check failed.', [
                'sessionId' => $sessionId,
                'exception' => $exception,
            ]);
        }
        try {
            $this->blockTracker->track($sessionId, $payload);
        } catch (\Throwable $exception) {
            $this->logger->error('Tracking the slots BK episodes failed.', [
                'sessionId' => $sessionId,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Story 30.45: dates the slots whose checks grew since the previous push.
     *
     * @param array<array-key, mixed>|null $previous
     * @param array<array-key, mixed>      $payload
     */
    private function datePlayedSlots(string $sessionId, ?array $previous, array $payload): void
    {
        $names = SlotCheckActivity::slotsWithNewChecks($previous, $payload);
        if ([] === $names) {
            return;
        }
        $now = $this->clock->now();
        foreach ($names as $slotName) {
            $this->slots->findBySessionAndSlotName($sessionId, $slotName)?->recordCheckActivity($now);
        }
        $this->slots->flush();
    }
}
