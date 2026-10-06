<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\QuestAchievementsQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Reads the weekly quests out of the pelles ledger (story 41.17): a quest paid has the key
 * `quest:{week}:{quest}:{member}`, a chest `quest-chest:{week}:{member}` (stories 41.6, 41.16).
 */
final readonly class DbalQuestAchievementsQuery implements QuestAchievementsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function questsCompleted(string $userId): int
    {
        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('pelle_movement')
            ->where('user_id = :userId')
            ->andWhere('unique_key LIKE :prefix')
            ->setParameter('userId', $userId)
            ->setParameter('prefix', 'quest:%')
            ->executeQuery()
            ->fetchOne();

        $count = filter_var($count, \FILTER_VALIDATE_INT);

        return false === $count ? 0 : $count;
    }

    public function chestWeeks(string $userId): array
    {
        $keys = $this->connection->createQueryBuilder()
            ->select('unique_key')
            ->from('pelle_movement')
            ->where('user_id = :userId')
            ->andWhere('unique_key LIKE :prefix')
            ->setParameter('userId', $userId)
            ->setParameter('prefix', 'quest-chest:%')
            ->executeQuery()
            ->fetchFirstColumn();

        $weeks = [];
        foreach ($keys as $key) {
            $parts = is_string($key) ? explode(':', $key) : [];
            if (3 === \count($parts)) {
                $weeks[] = $parts[1];
            }
        }

        return $weeks;
    }
}
