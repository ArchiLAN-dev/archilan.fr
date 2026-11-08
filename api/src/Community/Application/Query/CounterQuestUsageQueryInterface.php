<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Story 41.72: the weekly quests that read a counter of checks or items, so a counter in use is not deleted. Read
 * from `quest_definition` without importing Wallet.
 */
interface CounterQuestUsageQueryInterface
{
    /**
     * How many quests (in the draw or not, served or not) have an objective on each counter.
     *
     * @return array<string, int> counter id => quests
     */
    public function quests(): array;
}
