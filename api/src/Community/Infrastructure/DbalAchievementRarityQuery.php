<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\AchievementRarityQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Rarity snapshot for the achievements catalogue (story 30.31): distinct holders per achievement key and
 * the listable-member base, both restricted to listable members (a public slug, not deleted) so the
 * percentage matches the population shown across the community surfaces.
 */
final readonly class DbalAchievementRarityQuery implements AchievementRarityQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function snapshot(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('g.achievement_key AS k', 'COUNT(DISTINCT g.user_id) AS cnt')
            ->from('community_achievement_grant', 'g')
            ->join('g', $this->userTable, 'u', $qb->expr()->eq('u.id', 'g.user_id'))
            ->where('u.slug IS NOT NULL')
            ->andWhere($qb->expr()->isNull('u.deleted_at'))
            ->groupBy('g.achievement_key')
            ->executeQuery()
            ->fetchAllAssociative();

        $grantsByKey = [];
        foreach ($rows as $row) {
            $key = $row['k'] ?? null;
            if (is_string($key)) {
                $grantsByKey[$key] = is_numeric($row['cnt'] ?? null) ? (int) $row['cnt'] : 0;
            }
        }

        $countQb = $this->connection->createQueryBuilder();
        $memberCount = $countQb
            ->select('COUNT(u.id)')
            ->from($this->userTable, 'u')
            ->where('u.slug IS NOT NULL')
            ->andWhere($countQb->expr()->isNull('u.deleted_at'))
            ->executeQuery()
            ->fetchOne();

        return [
            'grantsByKey' => $grantsByKey,
            'memberCount' => is_numeric($memberCount) ? (int) $memberCount : 0,
        ];
    }
}
