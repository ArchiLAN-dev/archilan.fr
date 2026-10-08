<?php

declare(strict_types=1);

namespace App\Community\Application\Port;

/**
 * Story 30.52: pelles given to a member for a completed collection. The ledger lives in Wallet, which implements
 * this port: Community never depends on Wallet.
 */
interface PelleRewardInterface
{
    /**
     * Credits the member once per `$uniqueKey`.
     *
     * @return bool true when credited now, false when already credited or not allowed (a banned member)
     */
    public function credit(string $userId, int $amount, string $label, string $uniqueKey): bool;
}
