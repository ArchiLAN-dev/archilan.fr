<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Sessions\Application\CommunityStatsQueryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\WeeklyRuns\Domain\WeeklyEntry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DbalCommunityStatsQuery implements CommunityStatsQueryInterface
{
    private string $sessionTable;
    private string $slotTable;
    private string $weeklyEntryTable;

    public function __construct(private Connection $connection, EntityManagerInterface $em)
    {
        $this->sessionTable = $em->getClassMetadata(Session::class)->getTableName();
        $this->slotTable = $em->getClassMetadata(SessionSlot::class)->getTableName();
        $this->weeklyEntryTable = $em->getClassMetadata(WeeklyEntry::class)->getTableName();
    }

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

        // Weekly runs live in their own table and never touch session_slot. A completed weekly entry
        // (goal_reached_at set) counts as one finished run and one goal, contributing its checks_total.
        $weeklyQb = $this->connection->createQueryBuilder();
        $weeklyRow = $weeklyQb
            ->select(
                'COUNT(*) AS finished_count',
                'COALESCE(SUM(we.checks_total), 0) AS total_checks_done',
            )
            ->from($this->weeklyEntryTable, 'we')
            ->where($weeklyQb->expr()->isNotNull('we.goal_reached_at'))
            ->executeQuery()
            ->fetchAssociative();

        $weeklyFinished = $this->intVal($weeklyRow, 'finished_count');

        return [
            'totalFinishedSessions' => $totalFinishedSessions + $weeklyFinished,
            'totalChecksDone' => $this->intVal($slotsRow, 'total_checks_done') + $this->intVal($weeklyRow, 'total_checks_done'),
            'totalGoalsReached' => $this->intVal($slotsRow, 'total_goals_reached') + $weeklyFinished,
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
