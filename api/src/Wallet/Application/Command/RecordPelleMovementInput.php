<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * What a movement of the pelles ledger needs (story 41.1). `byAdmin` lets an admin act on a banned member
 * (AC4bis): nothing else may credit or debit a banned account.
 */
final readonly class RecordPelleMovementInput
{
    public function __construct(
        public string $userId,
        public int $amount,
        public PelleKind $kind,
        public ?string $eventId,
        public PelleReason $reason,
        public string $label,
        public ?string $authorId,
        public ?string $uniqueKey,
        public bool $byAdmin = false,
    ) {
    }
}
