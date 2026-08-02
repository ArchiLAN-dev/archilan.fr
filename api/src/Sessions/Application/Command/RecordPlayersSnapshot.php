<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Keeps the last players state the bridge pushed for a session (story 17.21): one row per
 * session, overwritten on every push, so the Progression tab can show the last known state when
 * the bridge is gone (idle/stopped session, dead container).
 */
final readonly class RecordPlayersSnapshot
{
    public function __construct(
        private SessionPlayersSnapshotRepositoryInterface $snapshots,
        private ClockInterface $clock,
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

            return;
        }

        $this->snapshots->save(new SessionPlayersSnapshot($sessionId, $payload, $this->clock->now()));
    }
}
