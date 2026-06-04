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

        return array_merge($eventRows, $prRows);
    }
}
