<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\EventFriendsQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Registrations x accepted friendships (story 43.4), in one read for a whole list of events.
 */
final readonly class DbalEventFriendsQuery implements EventFriendsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function friendIdsByEvent(string $viewerId, array $eventIds): array
    {
        if ([] === $eventIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT r.event_id, r.user_id
               FROM registration r
               JOIN event e ON e.id = r.event_id
               JOIN community_friendship f
                 ON f.status = 'accepted'
                AND ((f.requester_id = :viewerId AND f.addressee_id = r.user_id)
                  OR (f.addressee_id = :viewerId AND f.requester_id = r.user_id))
              WHERE r.event_id IN (:eventIds)
                AND r.status = 'reserved'
                AND e.status <> 'draft'
                AND (e.is_public
                     OR EXISTS (SELECT 1 FROM event_private_access_log l
                                 WHERE l.event_id = e.id AND l.user_id = :viewerId AND l.granted)
                     OR EXISTS (SELECT 1 FROM registration mine
                                 WHERE mine.event_id = e.id AND mine.user_id = :viewerId AND mine.status = 'reserved'))
                AND NOT EXISTS (SELECT 1 FROM community_block b
                                 WHERE (b.blocker_id = :viewerId AND b.blocked_id = r.user_id)
                                    OR (b.blocker_id = r.user_id AND b.blocked_id = :viewerId))
              ORDER BY r.event_id, COALESCE(r.submitted_at, r.created_at), r.user_id",
            ['viewerId' => $viewerId, 'eventIds' => $eventIds],
            ['eventIds' => ArrayParameterType::STRING],
        );

        $byEvent = [];
        foreach ($rows as $row) {
            $eventId = $row['event_id'] ?? null;
            $userId = $row['user_id'] ?? null;
            if (is_string($eventId) && is_string($userId)) {
                $byEvent[$eventId][] = $userId;
            }
        }

        return $byEvent;
    }
}
