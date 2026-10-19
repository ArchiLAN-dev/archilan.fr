<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Sessions\Domain\Entity\ItemBounty;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * The pelles a bounty holds (story 41.38): the run's bronze when it was posted in a private run, gold otherwise -
 * including a bounty posted in a private run before bronze existed. Everything that pays or gives a bounty back
 * moves those pelles, never the ones the rule would pick today.
 */
final readonly class BountyPurse
{
    public static function kindOf(ItemBounty $bounty): PelleKind
    {
        return null === $bounty->getRunId() ? PelleKind::Gold : PelleKind::Bronze;
    }

    public static function movement(ItemBounty $bounty, string $userId, int $amount, PelleReason $reason, string $label, string $key, bool $byAdmin = false): RecordPelleMovementInput
    {
        return new RecordPelleMovementInput(
            $userId, $amount, self::kindOf($bounty), null, $reason, $label, null, $key,
            byAdmin: $byAdmin,
            runId: $bounty->getRunId(),
        );
    }
}
