<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CommunityPresenceQueryInterface;
use App\Events\Domain\Entity\Event;
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
    private const string RUNNING = 'running';

    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
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

    public function playingNow(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        // Same two shapes as playing(), without the id filter and restricted to listable members: the
        // hub renders every row as a profile link, so a slug-less or deleted account has nothing to
        // point at. Each branch is capped at $limit; the merge below re-sorts and cuts to size.
        $eventQb = $this->connection->createQueryBuilder();
        $eventRows = $eventQb
            ->select('reg.user_id AS user_id', 's.id AS session_id', 'g.name AS game', 's.started_at AS started_at')
            ->from('session_slot', 'slot')
            ->join('slot', 'registration', 'reg', $eventQb->expr()->eq('reg.id', 'slot.registration_id'))
            ->join('slot', 'session', 's', $eventQb->expr()->eq('s.id', 'slot.session_id'))
            ->join('reg', $this->userTable, 'u', $eventQb->expr()->eq('u.id', 'reg.user_id'))
            ->leftJoin('slot', 'game', 'g', $eventQb->expr()->eq('g.id', 'slot.game_id'))
            ->where($eventQb->expr()->eq('s.status', ':status'))
            ->andWhere('u.slug IS NOT NULL')
            ->andWhere($eventQb->expr()->isNull('u.deleted_at'))
            ->setParameter('status', self::RUNNING)
            ->orderBy('s.started_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $prQb = $this->connection->createQueryBuilder();
        $prRows = $prQb
            ->select('slot.registration_id AS user_id', 's.id AS session_id', 'g.name AS game', 's.started_at AS started_at')
            ->from('session_slot', 'slot')
            ->join('slot', 'session', 's', $prQb->expr()->eq('s.id', 'slot.session_id'))
            ->join('s', 'run', 'pr', $prQb->expr()->eq('pr.session_id', 's.id'))
            ->join('slot', $this->userTable, 'u', $prQb->expr()->eq('u.id', 'slot.registration_id'))
            ->leftJoin('slot', 'game', 'g', $prQb->expr()->eq('g.id', 'slot.game_id'))
            ->where($prQb->expr()->eq('s.status', ':status'))
            ->andWhere('u.slug IS NOT NULL')
            ->andWhere($prQb->expr()->isNull('u.deleted_at'))
            ->setParameter('status', self::RUNNING)
            ->orderBy('s.started_at', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        $merged = [];
        foreach ([...$eventRows, ...$prRows] as $row) {
            $userId = $row['user_id'] ?? null;
            $sessionId = $row['session_id'] ?? null;
            if (!is_string($userId) || !is_string($sessionId) || isset($merged[$userId])) {
                continue;
            }
            $game = $row['game'] ?? null;
            $startedAt = $row['started_at'] ?? null;
            $merged[$userId] = [
                'userId' => $userId,
                'sessionId' => $sessionId,
                'game' => is_string($game) ? $game : null,
                'startedAt' => is_string($startedAt) ? $startedAt : '',
            ];
        }

        // Most recently started first; ties broken by user id so paging/caching stays deterministic.
        uasort($merged, static fn (array $a, array $b): int => $b['startedAt'] <=> $a['startedAt'] ?: strcmp($a['userId'], $b['userId']));

        $rows = [];
        foreach (array_slice(array_values($merged), 0, $limit) as $row) {
            $rows[] = ['userId' => $row['userId'], 'sessionId' => $row['sessionId'], 'game' => $row['game']];
        }

        return $rows;
    }
}
