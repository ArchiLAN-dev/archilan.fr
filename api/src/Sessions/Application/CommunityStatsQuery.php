<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use Doctrine\DBAL\Connection;

final readonly class CommunityStatsQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{totalFinishedSessions: int, totalChecksDone: int, totalGoalsReached: int}
     */
    public function execute(): array
    {
        $sessionsCount = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM archipelago_sessions WHERE status = 'finished'",
        );
        $totalFinishedSessions = is_numeric($sessionsCount) ? (int) $sessionsCount : 0;

        $slotsRow = $this->connection->fetchAssociative(
            <<<SQL
                SELECT
                    COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL)
                                      THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done,
                    COUNT(slot.goal_reached_at) AS total_goals_reached
                FROM archipelago_session_slots slot
                JOIN archipelago_sessions s ON slot.session_id = s.id
                WHERE s.status = 'finished'
            SQL,
        );

        return [
            'totalFinishedSessions' => $totalFinishedSessions,
            'totalChecksDone' => $this->intVal($slotsRow, 'total_checks_done'),
            'totalGoalsReached' => $this->intVal($slotsRow, 'total_goals_reached'),
        ];
    }

    /** @param array<string, mixed>|false $row */
    private function intVal(array|false $row, string $key): int
    {
        if (false === $row) {
            return 0;
        }

        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
    }
}
