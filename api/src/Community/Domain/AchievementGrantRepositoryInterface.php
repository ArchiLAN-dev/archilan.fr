<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface AchievementGrantRepositoryInterface
{
    /**
     * @return list<string> the achievement keys already granted to the user
     */
    public function grantedKeys(string $userId): array;

    /**
     * @return list<AchievementGrant>
     */
    public function findByUser(string $userId): array;

    public function save(AchievementGrant $grant): void;
}
