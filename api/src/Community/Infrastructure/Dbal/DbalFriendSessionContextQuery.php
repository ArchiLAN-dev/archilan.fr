<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\FriendSessionContextQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * A session's `event_id` is overloaded: a real event, else a personal run id (see SessionRecapAudience). The access
 * rule is the one of story 43.5: a public event, or a run the viewer owns or takes part in.
 */
final readonly class DbalFriendSessionContextQuery implements FriendSessionContextQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forViewer(array $sessionIds, string $viewerId): array
    {
        if ([] === $sessionIds) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                's.id AS session_id',
                'e.id AS event_id',
                'e.title AS event_title',
                'e.is_public AS event_public',
                'r.id AS run_id',
                'r.title AS run_title',
                'CASE WHEN r.owner_id = :viewer OR EXISTS (SELECT 1 FROM run_participant rp WHERE rp.personal_run_id = r.id AND rp.user_id = :viewer)'
                    .' THEN 1 ELSE 0 END AS run_member',
            )
            ->from('session', 's')
            ->leftJoin('s', 'event', 'e', $qb->expr()->eq('e.id', 's.event_id'))
            ->leftJoin('s', 'run', 'r', $qb->expr()->eq('r.id', 's.event_id'))
            ->where($qb->expr()->in('s.id', ':ids'))
            ->setParameter('ids', $sessionIds, ArrayParameterType::STRING)
            ->setParameter('viewer', $viewerId)
            ->executeQuery()
            ->fetchAllAssociative();

        $contexts = [];
        foreach ($rows as $row) {
            $sessionId = $row['session_id'] ?? null;
            if (!is_string($sessionId)) {
                continue;
            }
            $eventId = $row['event_id'] ?? null;
            if (is_string($eventId)) {
                $public = in_array($row['event_public'] ?? null, [true, 1, '1'], true);
                $title = $row['event_title'] ?? null;
                $contexts[$sessionId] = [
                    'kind' => 'event',
                    'title' => $public && is_string($title) ? $title : null,
                    'eventId' => $public ? $eventId : null,
                    'runId' => null,
                ];
                continue;
            }
            $runId = $row['run_id'] ?? null;
            if (is_string($runId)) {
                $member = in_array($row['run_member'] ?? null, [true, 1, '1'], true);
                $title = $row['run_title'] ?? null;
                $contexts[$sessionId] = [
                    'kind' => 'run',
                    'title' => $member && is_string($title) ? $title : null,
                    'eventId' => null,
                    'runId' => $member ? $runId : null,
                ];
            }
        }

        return $contexts;
    }
}
