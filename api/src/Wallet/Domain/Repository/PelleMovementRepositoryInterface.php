<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Repository;

use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;

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

    /** The member's balance of one kind (and of one event, for event pelles). */
    public function balance(string $userId, PelleKind $kind, ?string $eventId): int;

    public function findByUniqueKey(string $uniqueKey): ?PelleMovement;

    public function save(PelleMovement $movement): void;
}
