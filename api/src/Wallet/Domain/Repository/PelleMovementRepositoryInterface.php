<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Repository;

use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

interface PelleMovementRepositoryInterface
{
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * Locks the member's row until the transaction ends, so two movements of the same member never read
     * the same balance (story 41.1 AC3). Must be called inside beginTransaction() / commit().
     */
    public function lockMember(string $userId): void;

    /** The member's balance of one kind (and of one event, for event pelles; of one run, for bronze). */
    public function balance(string $userId, PelleKind $kind, ?string $eventId, ?string $runId = null): int;

    /**
     * Story 41.36: the positive bronze balances of a run, by member.
     *
     * @return array<string, int>
     */
    public function bronzeBalancesOfRun(string $runId): array;

    /**
     * Story 41.36: what the end of a run took from each member, and what its restorations gave back - totals and
     * counts, never an order (the ledger's dates are to the second).
     *
     * @return array<string, array{burned: int, burns: int, restored: int, restores: int}>
     */
    public function bronzeBurnsOfRun(string $runId): array;

    /** Story 41.40: what one reason moved for a member within an event, e.g. what the blind test earned them. */
    public function totalOfReason(string $userId, string $eventId, PelleReason $reason): int;

    public function findByUniqueKey(string $uniqueKey): ?PelleMovement;

    public function save(PelleMovement $movement): void;
}
