<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\CommunityPresenceQueryInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads "currently playing" presence from the live session tables (story 30.14): a user is playing when
 * they hold a slot in a session whose status is 'running'. Covers both event sessions (slot ->
 * registration -> user) and personal runs (slot.registration_id is the user id directly), mirroring
 * DbalPlayerHistoryQuery.
 */
final readonly class DbalCommunityPresenceQuery implements CommunityPresenceQueryInterface
{
    private const RUNNING = 'running';

    public function __construct(private Connection $connection)
    {
    }

    public function playing(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $playing = [];

        // Event sessions: slot -> registration -> user.
        $eventQb = $this->connection->createQueryBuilder();
        $eventRows = $eventQb
            ->select('reg.user_id AS user_id', 's.id AS session_id', 'g.name AS game')
            ->from('session_slot', 'slot')
            ->join('slot', 'registration', 'reg', $eventQb->expr()->eq('reg.id', 'slot.registration_id'))
            ->join('slot', 'session', 's', $eventQb->expr()->eq('s.id', 'slot.session_id'))
            ->leftJoin('slot', 'game', 'g', $eventQb->expr()->eq('g.id', 'slot.game_id'))
            ->where($eventQb->expr()->in('reg.user_id', ':ids'))
            ->andWhere($eventQb->expr()->eq('s.status', ':status'))
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->setParameter('status', self::RUNNING)
            ->executeQuery()
            ->fetchAllAssociative();

        // Personal runs: slot.registration_id is the user id directly.
        $prQb = $this->connection->createQueryBuilder();
        $prRows = $prQb
            ->select('slot.registration_id AS user_id', 's.id AS session_id', 'g.name AS game')
            ->from('session_slot', 'slot')
            ->join('slot', 'session', 's', $prQb->expr()->eq('s.id', 'slot.session_id'))
            ->join('s', 'run', 'pr', $prQb->expr()->eq('pr.session_id', 's.id'))
            ->leftJoin('slot', 'game', 'g', $prQb->expr()->eq('g.id', 'slot.game_id'))
            ->where($prQb->expr()->in('slot.registration_id', ':ids'))
            ->andWhere($prQb->expr()->eq('s.status', ':status'))
            ->setParameter('ids', $userIds, ArrayParameterType::STRING)
            ->setParameter('status', self::RUNNING)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ([...$eventRows, ...$prRows] as $row) {
            $userId = $row['user_id'] ?? null;
            $sessionId = $row['session_id'] ?? null;
            if (!is_string($userId) || !is_string($sessionId) || isset($playing[$userId])) {
                continue;
            }
            $game = $row['game'] ?? null;
            $playing[$userId] = ['sessionId' => $sessionId, 'game' => is_string($game) ? $game : null];
        }

        return $playing;
    }
}
