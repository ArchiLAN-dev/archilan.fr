<?php

declare(strict_types=1);

namespace App\Community\Application;

interface AchievementRarityQueryInterface
{
    /**
     * One snapshot for computing « X % des joueurs l'ont » on the catalogue page: how many distinct
     * listable members hold each achievement key, plus the listable-member base size. One batch query
     * pair, not per-card.
     *
     * @return array{grantsByKey: array<string, int>, memberCount: int}
     */
    public function snapshot(): array;
}
