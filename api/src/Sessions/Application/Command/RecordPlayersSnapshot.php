<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Application\Service\SlotBlockTracker;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the last players state the bridge pushed for a session (story 17.21): one row per
 * session, overwritten on every push, so the Progression tab can show the last known state when
 * the bridge is gone (idle/stopped session, dead container).
 *
 * Story 40.1: the same push also follows the BK episodes of a private run's slots.
 */
final readonly class RecordPlayersSnapshot
{
    public function __construct(
        private SessionPlayersSnapshotRepositoryInterface $snapshots,
        private ClockInterface $clock,
        private SlotBlockTracker $blockTracker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public function record(string $sessionId, array $payload): void
    {
        $existing = $this->snapshots->findBySessionId($sessionId);
        if (null !== $existing) {
            $existing->refresh($payload, $this->clock->now());
            $this->snapshots->save($existing);
        } else {
            $this->snapshots->save(new SessionPlayersSnapshot($sessionId, $payload, $this->clock->now()));
        }

        // The snapshot is saved first: a BK tracking failure is logged and never costs it.
        try {
            $this->blockTracker->track($sessionId, $payload);
        } catch (\Throwable $exception) {
            $this->logger->error('Tracking the slots BK episodes failed.', [
                'sessionId' => $sessionId,
                'exception' => $exception,
            ]);
        }
    }
}
