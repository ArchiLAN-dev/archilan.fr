<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * What a member did with the weekly quests (story 41.17), for the achievement facts: read from the pelles ledger
 * without Community depending on Wallet.
 */
interface QuestAchievementsQueryInterface
{
    /** Quests paid to the member, all weeks together. */
    public function questsCompleted(string $userId): int;

    /**
     * The weeks whose chest the member opened (every quest of the week done).
     *
     * @return list<string> week keys (`2026-W41`)
     */
    public function chestWeeks(string $userId): array;
}
