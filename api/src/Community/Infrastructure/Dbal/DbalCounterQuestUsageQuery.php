<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CounterQuestUsageQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Story 41.72: reads the objectives a quest stores as JSON (`{metric, target, scope, scopeId}`), those aimed at a
 * counter (`scope` = `counter`), each quest once per counter. A served week reads the objectives it froze
 * (`quest_week_entry.objectives`), so those of the weeks not over count too, even after the quest was edited or
 * retired; a week entry without its own objectives reads the quest's.
 */
final readonly class DbalCounterQuestUsageQuery implements CounterQuestUsageQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function quests(string $currentWeek): array
    {
        return $this->usage($currentWeek, false);
    }

    public function collectiveQuests(string $currentWeek): array
    {
        return $this->usage($currentWeek, true);
    }

    /**
     * @return array<string, int>
     */
    private function usage(string $currentWeek, bool $collectiveOnly): array
    {
        $definitions = $this->connection->createQueryBuilder()
            ->select('q.id', 'q.objectives')
            ->from('quest_definition', 'q')
            ->where('q.retired_at IS NULL OR EXISTS (SELECT 1 FROM quest_week_entry w WHERE w.quest_id = q.id AND w.week_key >= :week)')
            ->setParameter('week', $currentWeek);
        $served = $this->connection->createQueryBuilder()
            ->select('e.quest_id AS id', 'e.objectives')
            ->from('quest_week_entry', 'e')
            ->join('e', 'quest_definition', 'q', 'q.id = e.quest_id')
            ->where('e.week_key >= :week AND e.objectives IS NOT NULL')
            ->setParameter('week', $currentWeek);
        if ($collectiveOnly) {
            $definitions->andWhere('q.collective = TRUE');
            $served->andWhere('q.collective = TRUE');
        }

        $readers = [];
        foreach ([...$definitions->executeQuery()->fetchAllAssociative(), ...$served->executeQuery()->fetchAllAssociative()] as $row) {
            $questId = $row['id'] ?? null;
            $objectives = is_string($row['objectives'] ?? null) ? json_decode($row['objectives'], true) : null;
            if (!is_string($questId) || !is_array($objectives)) {
                continue;
            }
            foreach ($objectives as $objective) {
                if (is_array($objective) && 'counter' === ($objective['scope'] ?? null) && is_string($objective['scopeId'] ?? null)) {
                    $readers[$objective['scopeId']][$questId] = true;
                }
            }
        }

        return array_map(count(...), $readers);
    }
}
