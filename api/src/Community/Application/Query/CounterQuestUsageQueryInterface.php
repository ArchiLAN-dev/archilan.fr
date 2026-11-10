<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * Story 41.72: the weekly quests that read a counter of checks or items, so a counter in use is neither deleted nor
 * changed in a way they cannot follow. Read from `quest_definition` and `quest_week_entry` without importing Wallet.
 */
interface CounterQuestUsageQueryInterface
{
    /**
     * How many quests read each counter: a quest not retired, in the draw or not, and a quest served this week or
     * a coming one, through the objectives its week froze or, without them, its own.
     *
     * @param string $currentWeek the key of the week now (`2026-W45`, Paris time)
     *
     * @return array<string, int> counter id => quests
     */
    public function quests(string $currentWeek): array;

    /**
     * The collective quests among them: they add up the counter as a total, so its mode stays `total`.
     *
     * @return array<string, int> counter id => collective quests
     */
    public function collectiveQuests(string $currentWeek): array;
}
