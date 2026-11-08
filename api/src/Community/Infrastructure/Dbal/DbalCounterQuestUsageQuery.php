<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CounterQuestUsageQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Story 41.72: reads the objectives a quest stores as JSON (`{metric, target, scope, scopeId}`), those aimed at a
 * counter (`scope` = `counter`), each quest once per counter.
 */
final readonly class DbalCounterQuestUsageQuery implements CounterQuestUsageQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function quests(): array
    {
        return $this->usage(false);
    }

    public function collectiveQuests(): array
    {
        return $this->usage(true);
    }

    /**
     * @return array<string, int>
     */
    private function usage(bool $collectiveOnly): array
    {
        $usage = [];
        $sql = 'SELECT q.id, q.objectives FROM quest_definition q'.($collectiveOnly ? ' WHERE q.collective' : '');
        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            $objectives = is_string($row['objectives'] ?? null) ? json_decode($row['objectives'], true) : null;
            if (!is_array($objectives)) {
                continue;
            }
            $counters = [];
            foreach ($objectives as $objective) {
                if (is_array($objective) && 'counter' === ($objective['scope'] ?? null) && is_string($objective['scopeId'] ?? null)) {
                    $counters[$objective['scopeId']] = true;
                }
            }
            foreach (array_keys($counters) as $id) {
                $usage[$id] = ($usage[$id] ?? 0) + 1;
            }
        }

        return $usage;
    }
}
