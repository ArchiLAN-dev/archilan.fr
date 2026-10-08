<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Adapter;

use App\Community\Application\Port\PelleRewardInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/** Story 30.52: a completed collection's pelles, through the one place pelles move. */
final readonly class LedgerPelleReward implements PelleRewardInterface
{
    public function __construct(private RecordPelleMovement $record)
    {
    }

    public function credit(string $userId, int $amount, string $label, string $uniqueKey): bool
    {
        if ($amount <= 0) {
            return false;
        }
        try {
            $recorded = $this->record->record(new RecordPelleMovementInput($userId, $amount, PelleKind::Gold, null, PelleReason::CollectionReward, $label, null, $uniqueKey));
        } catch (ForbiddenException|NotFoundException) {
            // A banned or deleted member earns nothing; the collection is still theirs.
            return false;
        }

        return !$recorded->alreadyRecorded;
    }
}
