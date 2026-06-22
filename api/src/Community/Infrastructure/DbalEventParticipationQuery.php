<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\EventParticipationQueryInterface;
use Doctrine\DBAL\Connection;

/**
 * Reads the cross-context registration → session → session_slot chain by table name (the precedent set by
 * DbalPlayerStatsQuery) so the Community engine learns about event goals without importing the Events /
 * Registrations / Sessions domains (story 30.32). Goal-scoped only: a goal implies a finished session.
 */
final readonly class DbalEventParticipationQuery implements EventParticipationQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function eventIdsWithGoal(string $userId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('r.event_id AS event_id')
            ->distinct()
            ->from('registration', 'r')
            ->join('r', 'session_slot', 'slot', 'slot.registration_id = r.id')
            ->join('slot', 'session', 's', 's.id = slot.session_id AND s.event_id = r.event_id')
            ->where($qb->expr()->eq('r.user_id', ':userId'))
            ->andWhere($qb->expr()->eq('r.status', ':reserved'))
            ->andWhere('r.submitted_at IS NOT NULL')
            ->andWhere($qb->expr()->eq('s.status', ':finished'))
            ->andWhere('slot.goal_reached_at IS NOT NULL')
            ->setParameter('userId', $userId)
            ->setParameter('reserved', 'reserved')
            ->setParameter('finished', 'finished')
            ->executeQuery()
            ->fetchAllAssociative();

        $ids = [];
        foreach ($rows as $row) {
            $eventId = $row['event_id'] ?? null;
            if (is_string($eventId)) {
                $ids[] = $eventId;
            }
        }

        return $ids;
    }
}
