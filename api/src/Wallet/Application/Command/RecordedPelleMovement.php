<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/**
 * The outcome of {@see RecordPelleMovement}: the line written (or the one already written under the same
 * key) and the balance around it.
 */
final readonly class RecordedPelleMovement
{
    public function __construct(
        public string $movementId,
        public int $balanceBefore,
        public int $balanceAfter,
        public bool $alreadyRecorded,
    ) {
    }
}
