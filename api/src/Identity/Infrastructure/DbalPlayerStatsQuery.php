<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\PlayerStatsQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalPlayerStatsQuery implements PlayerStatsQueryInterface
{
    private const SESSION_TABLE = 'session';
    private const SLOT_TABLE = 'session_slot';
    private const REGISTRATION_TABLE = 'registration';
    private const RUN_TABLE = 'run';
    private const WEEKLY_ENTRY_TABLE = 'weekly_entries';

    // A released slot that never reached a goal is excluded from games/checks/items everywhere.
    private const COUNTS = 'NOT (slot.was_released AND slot.goal_reached_at IS NULL)';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return array{runs_participated: int, games_played: int, goal_completions: int, total_checks_done: int, total_items_received: int}
     */
    public function computeForUser(string $userId): array
    {
        return $this->computeForUsers([$userId])[$userId] ?? $this->zero();
    }

    /**
     * @param list<string>|null $userIds
     *
     * @return array<string, array{runs_participated: int, games_played: int, goal_completions: int, total_checks_done: int, total_items_received: int}>
     */
    public function computeForUsers(?array $userIds): array
    {
        if (null !== $userIds && [] === $userIds) {
            return [];
        }

        /** @var array<string, array{runs_participated: int, games_played: int, goal_completions: int, total_checks_done: int, total_items_received: int}> $out */
        $out = [];

        // Event sessions (slot -> registration -> user). Goals/games counted per slot (= per game) so a
        // multi-game run isn't undercounted (story 18.8).
        foreach ($this->sessionRows('reg.user_id', true, $userIds) as $row) {
            $this->accumulateSession($out, $row);
        }

        // Personal runs (slot.registration_id = user). A personal run counts only when the player reached
        // a goal in it (story 17.15) - gated at the SESSION level so the other games of a counted run still
        // feed games_played and checks/items (story 18.8).
        foreach ($this->sessionRows('slot.registration_id', false, $userIds) as $row) {
            $this->accumulateSession($out, $row);
        }

        // Weekly runs live in their own table. A completed weekly entry = one finished run = one game =
        // one goal, with its checks/items totals (story 18.9).
        foreach ($this->weeklyRows($userIds) as $row) {
            $this->accumulateWeekly($out, $row);
        }

        return $out;
    }

    /**
     * @param list<string>|null $userIds
     *
     * @return list<array<string, mixed>>
     */
    private function sessionRows(string $userExpr, bool $eventPath, ?array $userIds): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                $userExpr.' AS uid',
                'COUNT(DISTINCT s.id) AS runs_participated',
                'COALESCE(SUM(CASE WHEN '.self::COUNTS.' THEN 1 ELSE 0 END), 0) AS games_played',
                'COUNT(slot.goal_reached_at) AS goal_completions',
                'COALESCE(SUM(CASE WHEN '.self::COUNTS.' THEN slot.checks_done ELSE 0 END), 0) AS total_checks_done',
                'COALESCE(SUM(CASE WHEN '.self::COUNTS.' THEN slot.items_received ELSE 0 END), 0) AS total_items_received',
            )
            ->from(self::SLOT_TABLE, 'slot')
            ->join('slot', self::SESSION_TABLE, 's', $qb->expr()->eq('s.id', 'slot.session_id'))
            ->where($qb->expr()->eq('s.status', ':status'))
            ->setParameter('status', 'finished')
            ->groupBy($userExpr);

        if ($eventPath) {
            $qb->join('slot', self::REGISTRATION_TABLE, 'reg', $qb->expr()->eq('reg.id', 'slot.registration_id'));
        } else {
            $qb->join('s', self::RUN_TABLE, 'pr', $qb->expr()->eq('pr.session_id', 's.id'))
                ->andWhere('s.id IN (SELECT g.session_id FROM '.self::SLOT_TABLE.' g WHERE g.registration_id = slot.registration_id AND g.goal_reached_at IS NOT NULL)');
        }

        if (null !== $userIds) {
            $qb->andWhere($qb->expr()->in($userExpr, ':ids'))->setParameter('ids', $userIds, ArrayParameterType::STRING);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<string>|null $userIds
     *
     * @return list<array<string, mixed>>
     */
    private function weeklyRows(?array $userIds): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'we.user_id AS uid',
                'COUNT(*) AS completed_count',
                'COALESCE(SUM(we.checks_total), 0) AS total_checks_done',
                'COALESCE(SUM(we.items_total), 0) AS total_items_received',
            )
            ->from(self::WEEKLY_ENTRY_TABLE, 'we')
            ->where($qb->expr()->isNotNull('we.goal_reached_at'))
            ->groupBy('we.user_id');

        if (null !== $userIds) {
            $qb->andWhere($qb->expr()->in('we.user_id', ':ids'))->setParameter('ids', $userIds, ArrayParameterType::STRING);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, array{runs_participated: int, games_played: int, goal_completions: int, total_checks_done: int, total_items_received: int}> $out
     * @param array<string, mixed>                                                                                                                      $row
     */
    private function accumulateSession(array &$out, array $row): void
    {
        $uid = $row['uid'] ?? null;
        if (!is_string($uid)) {
            return;
        }
        $cur = $out[$uid] ?? $this->zero();
        $out[$uid] = [
            'runs_participated' => $cur['runs_participated'] + $this->intVal($row, 'runs_participated'),
            'games_played' => $cur['games_played'] + $this->intVal($row, 'games_played'),
            'goal_completions' => $cur['goal_completions'] + $this->intVal($row, 'goal_completions'),
            'total_checks_done' => $cur['total_checks_done'] + $this->intVal($row, 'total_checks_done'),
            'total_items_received' => $cur['total_items_received'] + $this->intVal($row, 'total_items_received'),
        ];
    }

    /**
     * @param array<string, array{runs_participated: int, games_played: int, goal_completions: int, total_checks_done: int, total_items_received: int}> $out
     * @param array<string, mixed>                                                                                                                      $row
     */
    private function accumulateWeekly(array &$out, array $row): void
    {
        $uid = $row['uid'] ?? null;
        if (!is_string($uid)) {
            return;
        }
        $completed = $this->intVal($row, 'completed_count');
        $cur = $out[$uid] ?? $this->zero();
        $out[$uid] = [
            'runs_participated' => $cur['runs_participated'] + $completed,
            'games_played' => $cur['games_played'] + $completed,
            'goal_completions' => $cur['goal_completions'] + $completed,
            'total_checks_done' => $cur['total_checks_done'] + $this->intVal($row, 'total_checks_done'),
            'total_items_received' => $cur['total_items_received'] + $this->intVal($row, 'total_items_received'),
        ];
    }

    /**
     * @return array{runs_participated: int, games_played: int, goal_completions: int, total_checks_done: int, total_items_received: int}
     */
    private function zero(): array
    {
        return [
            'runs_participated' => 0,
            'games_played' => 0,
            'goal_completions' => 0,
            'total_checks_done' => 0,
            'total_items_received' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function intVal(array $row, string $key): int
    {
        return is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
    }
}
