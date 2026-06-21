<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure;

use App\Identity\Application\PlayerHistoryQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalPlayerHistoryQuery implements PlayerHistoryQueryInterface
{
    private const SESSION_TABLE = 'session';
    private const SLOT_TABLE = 'session_slot';
    private const REGISTRATION_TABLE = 'registration';
    private const RUN_TABLE = 'run';
    private const EVENT_TABLE = 'event';
    private const GAME_TABLE = 'game';
    private const WEEKLY_ENTRY_TABLE = 'weekly_entries';
    private const WEEKLY_RUN_TABLE = 'weekly_runs';
    private const WEEKLY_TEMPLATE_TABLE = 'weekly_templates';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchForUser(string $userId): array
    {
        $eventQb = $this->connection->createQueryBuilder();
        $eventRows = $eventQb
            ->select(
                's.id AS session_id',
                'e.title AS event_name',
                's.finished_at',
                'g.name AS game',
                'slot.checks_done',
                'slot.items_received',
                'slot.goal_reached_at',
                'slot.was_released',
            )
            ->from(self::SLOT_TABLE, 'slot')
            ->join('slot', self::REGISTRATION_TABLE, 'reg', $eventQb->expr()->eq('reg.id', 'slot.registration_id'))
            ->join('slot', self::SESSION_TABLE, 's', $eventQb->expr()->eq('s.id', 'slot.session_id'))
            ->join('s', self::EVENT_TABLE, 'e', $eventQb->expr()->eq('e.id', 's.event_id'))
            ->join('slot', self::GAME_TABLE, 'g', $eventQb->expr()->eq('g.id', 'slot.game_id'))
            ->where($eventQb->expr()->eq('reg.user_id', ':userId'))
            ->andWhere($eventQb->expr()->eq('s.status', ':status'))
            ->setParameter('userId', $userId)
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchAllAssociative();

        $prQb = $this->connection->createQueryBuilder();
        $prRows = $prQb
            ->select(
                's.id AS session_id',
                'pr.title AS event_name',
                's.finished_at',
                'g.name AS game',
                'slot.checks_done',
                'slot.items_received',
                'slot.goal_reached_at',
                'slot.was_released',
            )
            ->from(self::SLOT_TABLE, 'slot')
            ->join('slot', self::SESSION_TABLE, 's', $prQb->expr()->eq('s.id', 'slot.session_id'))
            ->join('s', self::RUN_TABLE, 'pr', $prQb->expr()->eq('pr.session_id', 's.id'))
            ->join('slot', self::GAME_TABLE, 'g', $prQb->expr()->eq('g.id', 'slot.game_id'))
            ->where($prQb->expr()->eq('slot.registration_id', ':userId'))
            ->andWhere($prQb->expr()->eq('s.status', ':status'))
            ->setParameter('userId', $userId)
            ->setParameter('status', 'finished')
            ->executeQuery()
            ->fetchAllAssociative();

        // Weekly runs live in their own tables and never touch session_slot. A completed weekly entry
        // (goal_reached_at set) is a finished run for this player, contributing its game + checks/items
        // to the history (and thus the profile showcase). is_weekly lets the UI skip the
        // /runs/{id}/resultats link, which doesn't exist for weekly sessions.
        $weeklyQb = $this->connection->createQueryBuilder();
        $weeklyRows = $weeklyQb
            ->select(
                'COALESCE(we.external_session_id, we.id) AS session_id',
                "COALESCE(wt.name, 'Run hebdo') AS event_name",
                'we.goal_reached_at AS finished_at',
                'g.name AS game',
                'COALESCE(we.checks_total, 0) AS checks_done',
                'COALESCE(we.items_total, 0) AS items_received',
                'we.goal_reached_at AS goal_reached_at',
                '0 AS was_released',
                '1 AS is_weekly',
            )
            ->from(self::WEEKLY_ENTRY_TABLE, 'we')
            ->join('we', self::WEEKLY_RUN_TABLE, 'wr', $weeklyQb->expr()->eq('wr.id', 'we.weekly_run_id'))
            ->join('wr', self::WEEKLY_TEMPLATE_TABLE, 'wt', $weeklyQb->expr()->eq('wt.id', 'wr.template_id'))
            ->join('wt', self::GAME_TABLE, 'g', $weeklyQb->expr()->eq('g.id', 'wt.game_id'))
            ->where($weeklyQb->expr()->eq('we.user_id', ':userId'))
            ->andWhere($weeklyQb->expr()->isNotNull('we.goal_reached_at'))
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_merge($eventRows, $prRows, $weeklyRows);
    }
}
