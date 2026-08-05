<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\RecentAchievementGrantsQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Community-wide "who just unlocked what" feed for the hub (story 30.38). Same listable-member base as
 * DbalAchievementRarityQuery (a public slug, not deleted), so the hub never surfaces a member the
 * directory and the rarity percentages do not count.
 */
final readonly class DbalRecentAchievementGrantsQuery implements RecentAchievementGrantsQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function recent(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('g.user_id AS user_id', 'g.achievement_key AS achievement_key', 'g.unlocked_at AS unlocked_at')
            ->from('community_achievement_grant', 'g')
            ->join('g', $this->userTable, 'u', $qb->expr()->eq('u.id', 'g.user_id'))
            ->where('u.slug IS NOT NULL')
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->orderBy('g.unlocked_at', 'DESC')
            ->addOrderBy('g.id', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $grants = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;
            $key = $row['achievement_key'] ?? null;
            $unlockedAt = $row['unlocked_at'] ?? null;
            if (!is_string($userId) || !is_string($key) || !is_string($unlockedAt)) {
                continue;
            }
            // Normalised here, where the DB's timestamp format is known, so callers get the same ATOM
            // string the profile surfaces emit (AchievementGrant::getUnlockedAt()->format(ATOM)).
            try {
                $unlockedAt = new \DateTimeImmutable($unlockedAt)->format(\DateTimeInterface::ATOM);
            } catch (\Exception) {
                continue;
            }
            $grants[] = ['userId' => $userId, 'achievementKey' => $key, 'unlockedAt' => $unlockedAt];
        }

        return $grants;
    }
}
