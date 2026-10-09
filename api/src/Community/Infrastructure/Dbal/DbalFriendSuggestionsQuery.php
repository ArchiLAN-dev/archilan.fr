<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\FriendSuggestionsQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

/**
 * One aggregated read (story 43.2): every session's players (owners and co-players, story 16.17), the ones the
 * user shares a session with, counted per kind - a personal run (`run.session_id`) or an event session
 * (`session.event_id`). A weekly attempt has no session slot, so it never makes a suggestion: it is a solo race.
 */
final readonly class DbalFriendSuggestionsQuery implements FriendSuggestionsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forUser(string $userId, ?string $sessionId, int $limit): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $slotColumn = DbalSlotPlayerSource::SLOT_COLUMN;
        $userColumn = DbalSlotPlayerSource::USER_COLUMN;
        $inSession = null === $sessionId ? '' : 'AND EXISTS (SELECT 1 FROM players ps WHERE ps.session_id = :sessionId AND ps.uid = c.uid)
                AND EXISTS (SELECT 1 FROM players pm WHERE pm.session_id = :sessionId AND pm.uid = :userId)';

        $rows = $this->connection->fetchAllAssociative(
            "WITH players AS (
                 SELECT DISTINCT slot.session_id, sp.{$userColumn} AS uid
                   FROM session_slot slot
                   JOIN {$players} sp ON sp.{$slotColumn} = slot.id
             ), shared AS (
                 SELECT p.uid, p.session_id
                   FROM players p
                   JOIN players me ON me.session_id = p.session_id AND me.uid = :userId
                  WHERE p.uid <> :userId
             ), tagged AS (
                 SELECT sh.uid, sh.session_id, r.id AS run_id, e.id AS event_id,
                        COALESCE(r.title, e.title) AS title, COALESCE(s.finished_at, s.created_at) AS played_at
                   FROM shared sh
                   JOIN session s ON s.id = sh.session_id
                   LEFT JOIN run r ON r.session_id = s.id
                   LEFT JOIN event e ON e.id = s.event_id
                  WHERE r.id IS NOT NULL OR e.id IS NOT NULL
             ), counted AS (
                 SELECT uid, COUNT(*) AS sessions, COUNT(run_id) AS runs, COUNT(event_id) AS events, MAX(played_at) AS last_at
                   FROM tagged GROUP BY uid
             ), latest AS (
                 SELECT DISTINCT ON (uid) uid, title FROM tagged ORDER BY uid, played_at DESC
             )
             SELECT c.uid, c.sessions, c.last_at, l.title
               FROM counted c
               JOIN latest l ON l.uid = c.uid
              WHERE (c.runs >= :minRuns OR c.events >= :minEvents)
                AND NOT EXISTS (SELECT 1 FROM community_friendship f
                                 WHERE (f.requester_id = :userId AND f.addressee_id = c.uid)
                                    OR (f.requester_id = c.uid AND f.addressee_id = :userId))
                AND NOT EXISTS (SELECT 1 FROM community_block b
                                 WHERE (b.blocker_id = :userId AND b.blocked_id = c.uid)
                                    OR (b.blocker_id = c.uid AND b.blocked_id = :userId))
                AND NOT EXISTS (SELECT 1 FROM community_friend_suggestion_dismissal d
                                 WHERE d.user_id = :userId AND d.dismissed_user_id = c.uid)
                {$inSession}
              ORDER BY c.sessions DESC, c.last_at DESC, c.uid
              LIMIT {$limit}",
            ['userId' => $userId, 'minRuns' => self::MIN_SHARED_RUNS, 'minEvents' => self::MIN_SHARED_EVENT_SESSIONS]
                + (null === $sessionId ? [] : ['sessionId' => $sessionId]),
        );

        $suggestions = [];
        foreach ($rows as $row) {
            $uid = $row['uid'] ?? null;
            $sessions = filter_var($row['sessions'] ?? null, \FILTER_VALIDATE_INT);
            if (!is_string($uid) || false === $sessions) {
                continue;
            }
            $lastAt = $row['last_at'] ?? null;
            $suggestions[] = [
                'userId' => $uid,
                'sessionsTogether' => $sessions,
                'lastTitle' => is_string($row['title'] ?? null) ? $row['title'] : null,
                'lastPlayedAt' => is_string($lastAt) ? new \DateTimeImmutable($lastAt)->format(\DATE_ATOM) : null,
            ];
        }

        return $suggestions;
    }
}
