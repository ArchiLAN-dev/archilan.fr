<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;

/**
 * Last-known players state for a session (story 17.21) - the fallback the Progression tab reads
 * when the live bridge is unreachable. Access control stays with the caller (the controller
 * applies the same auth as the live proxy).
 */
final readonly class PlayersSnapshotQuery
{
    public function __construct(
        private SessionPlayersSnapshotRepositoryInterface $snapshots,
    ) {
    }

    /**
     * @return array{payload: array<array-key, mixed>, updatedAt: string}|null
     */
    public function execute(string $sessionId): ?array
    {
        $snapshot = $this->snapshots->findBySessionId($sessionId);
        if (null === $snapshot) {
            return null;
        }

        return [
            'payload' => $snapshot->getPayload(),
            'updatedAt' => $snapshot->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
