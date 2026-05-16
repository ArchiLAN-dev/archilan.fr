<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CommunityStatsQuery
{
    private string $sessionTable;
    private string $slotTable;

    public function __construct(private Connection $connection, EntityManagerInterface $em)
    {
        $this->sessionTable = $em->getClassMetadata(Session::class)->getTableName();
        $this->slotTable = $em->getClassMetadata(SessionSlot::class)->getTableName();
    }

    /**
     * @return array{totalFinishedSessions: int, totalChecksDone: int, totalGoalsReached: int}
     */
    public function execute(): array
    {
        $countQb = $this->connection->createQueryBuilder();
        $countRaw = $countQb
            ->select('COUNT(*)')
            ->from($this->sessionTable, 's')
            ->where($countQb->expr()->eq('s.status', ':status'))
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchOne();
        $totalFinishedSessions = is_numeric($countRaw) ? (int) $countRaw : 0;

        $slotsQb = $this->connection->createQueryBuilder();
        $slotsRow = $slotsQb
            ->select(
                'COALESCE(SUM(CASE WHEN NOT (slot.was_released AND slot.goal_reached_at IS NULL) THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done',
                'COUNT(slot.goal_reached_at) AS total_goals_reached',
            )
            ->from($this->slotTable, 'slot')
            ->join('slot', $this->sessionTable, 's', $slotsQb->expr()->eq('s.id', 'slot.session_id'))
            ->where($slotsQb->expr()->eq('s.status', ':status'))
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchAssociative();

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
