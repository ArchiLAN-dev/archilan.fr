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

    /**
     * Batch count of granted achievements per user. Pass null for all users; users with zero grants are
     * absent from the map.
     *
     * @param list<string>|null $userIds
     *
     * @return array<string, int>
     */
    public function countByUsers(?array $userIds): array;

    /**
     * The id of the user who owns the grant, or null if no grant has that id.
     */
    public function ownerOf(string $grantId): ?string;

    public function save(AchievementGrant $grant): void;
}
