<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\PlayerStatsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalPlayerStatsQuery implements PlayerStatsQueryInterface
{
    private const SESSION_TABLE = 'session';
    private const SLOT_TABLE = 'session_slot';
    private const REGISTRATION_TABLE = 'registration';
    private const RUN_TABLE = 'run';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{runs_participated: int, goal_completions: int, total_checks_done: int, total_items_received: int}
     */
    public function computeForUser(string $userId): array
    {
        $eventQb = $this->connection->createQueryBuilder();
        $eventRow = $eventQb
            ->select(
                'COUNT(DISTINCT s.id) AS runs_participated',
                'COUNT(DISTINCT CASE WHEN slot.goal_reached_at IS NOT NULL THEN s.id END) AS goal_completions',
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done',
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.items_received ELSE 0 END), 0) AS total_items_received',
            )
            ->from(self::SLOT_TABLE, 'slot')
            ->join('slot', self::REGISTRATION_TABLE, 'reg', $eventQb->expr()->eq('reg.id', 'slot.registration_id'))
            ->join('slot', self::SESSION_TABLE, 's', $eventQb->expr()->eq('s.id', 'slot.session_id'))
            ->where($eventQb->expr()->eq('reg.user_id', ':userId'))
            ->andWhere($eventQb->expr()->eq('s.status', ':status'))
            ->setParameter('userId', $userId)
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchAssociative();

        $prQb = $this->connection->createQueryBuilder();
        $prRow = $prQb
            ->select(
                'COUNT(DISTINCT s.id) AS runs_participated',
                'COUNT(DISTINCT CASE WHEN slot.goal_reached_at IS NOT NULL THEN s.id END) AS goal_completions',
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done',
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.items_received ELSE 0 END), 0) AS total_items_received',
            )
            ->from(self::SLOT_TABLE, 'slot')
            ->join('slot', self::SESSION_TABLE, 's', $prQb->expr()->eq('s.id', 'slot.session_id'))
            ->join('s', self::RUN_TABLE, 'pr', $prQb->expr()->eq('pr.session_id', 's.id'))
            ->where($prQb->expr()->eq('slot.registration_id', ':userId'))
            ->andWhere($prQb->expr()->eq('s.status', ':status'))
            ->setParameter('userId', $userId)
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchAssociative();

        return [
            'runs_participated' => $this->intVal($eventRow, 'runs_participated') + $this->intVal($prRow, 'runs_participated'),
            'goal_completions' => $this->intVal($eventRow, 'goal_completions') + $this->intVal($prRow, 'goal_completions'),
            'total_checks_done' => $this->intVal($eventRow, 'total_checks_done') + $this->intVal($prRow, 'total_checks_done'),
            'total_items_received' => $this->intVal($eventRow, 'total_items_received') + $this->intVal($prRow, 'total_items_received'),
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
