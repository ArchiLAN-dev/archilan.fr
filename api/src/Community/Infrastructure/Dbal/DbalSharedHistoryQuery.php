<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\SharedHistoryQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Story 43.9: the shared sessions come from the same players read as the suggestions of 43.2 (owners and
 * co-players, story 16.17), a personal run (`run.session_id`) or an event session (`session.event_id`). A weekly
 * attempt has no session slot: it is never shared.
 *
 * An item counts as exchanged when the feed names a slot one member plays as the sender and a slot the other plays
 * as the receiver, the slot names leading to the players (DbalItemsFromOthersQuery's link). What a slot sent from
 * its release on, or received from its collect on, is left out: nobody found those.
 */
final readonly class DbalSharedHistoryQuery implements SharedHistoryQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function sessionsBetween(string $userId, string $otherId): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $slotColumn = DbalSlotPlayerSource::SLOT_COLUMN;
        $userColumn = DbalSlotPlayerSource::USER_COLUMN;

        $rows = $this->connection->fetchAllAssociative(
            "WITH players AS (
                 -- Story 43.19: only the two members' slots, not every slot of the history.
                 SELECT DISTINCT slot.session_id, sp.{$userColumn} AS uid
                   FROM session_slot slot
                   JOIN {$players} sp ON sp.{$slotColumn} = slot.id
                  WHERE sp.{$userColumn} IN (:userId, :otherId)
             )
             SELECT s.id AS session_id, r.id AS run_id, e.id AS event_id, COALESCE(r.title, e.title) AS title,
                    COALESCE(s.finished_at, s.started_at, s.created_at) AS played_at
               FROM players me
               JOIN players other ON other.session_id = me.session_id AND other.uid = :otherId
               JOIN session s ON s.id = me.session_id
               LEFT JOIN run r ON r.session_id = s.id
               LEFT JOIN event e ON e.id = s.event_id
              WHERE me.uid = :userId
                AND (r.id IS NOT NULL OR e.id IS NOT NULL)
              ORDER BY played_at DESC, s.id",
            ['userId' => $userId, 'otherId' => $otherId],
        );

        $sessions = [];
        foreach ($rows as $row) {
            $sessionId = $row['session_id'] ?? null;
            $playedAt = $row['played_at'] ?? null;
            if (!is_string($sessionId) || !is_string($playedAt)) {
                continue;
            }
            $sessions[] = [
                'sessionId' => $sessionId,
                'runId' => is_string($row['run_id'] ?? null) ? $row['run_id'] : null,
                'eventId' => is_string($row['event_id'] ?? null) ? $row['event_id'] : null,
                'title' => is_string($row['title'] ?? null) ? $row['title'] : null,
                'playedAt' => new \DateTimeImmutable($playedAt)->format(\DATE_ATOM),
            ];
        }

        return $sessions;
    }

    public function itemsBetween(string $userId, string $otherId, array $sessionIds): array
    {
        if ([] === $sessionIds) {
            return ['sent' => 0, 'received' => 0, 'since' => null];
        }

        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $slotColumn = DbalSlotPlayerSource::SLOT_COLUMN;
        $userColumn = DbalSlotPlayerSource::USER_COLUMN;

        $row = $this->connection->fetchAssociative(
            "SELECT COUNT(DISTINCT fe.id) FILTER (WHERE sp.{$userColumn} = :userId AND rp.{$userColumn} = :otherId) AS sent,
                    COUNT(DISTINCT fe.id) FILTER (WHERE sp.{$userColumn} = :otherId AND rp.{$userColumn} = :userId) AS received,
                    (SELECT MIN(f2.occurred_at) FROM session_feed_event f2 WHERE f2.session_id IN (:sessionIds)) AS since
               FROM session_feed_event fe
               JOIN session_slot ss ON ss.session_id = fe.session_id AND ss.slot_name = fe.sender_name
               JOIN {$players} sp ON sp.{$slotColumn} = ss.id
               JOIN session_slot rs ON rs.session_id = fe.session_id AND rs.slot_name = fe.receiver_name
               JOIN {$players} rp ON rp.{$slotColumn} = rs.id
              WHERE fe.session_id IN (:sessionIds)
                AND fe.type = :itemType
                AND ss.id <> rs.id
                AND (ss.released_at IS NULL OR fe.occurred_at < ss.released_at - INTERVAL '2 seconds')
                AND (rs.collected_at IS NULL OR fe.occurred_at < rs.collected_at - INTERVAL '2 seconds')",
            ['userId' => $userId, 'otherId' => $otherId, 'sessionIds' => $sessionIds, 'itemType' => 'item-received'],
            ['sessionIds' => ArrayParameterType::STRING],
        );

        $sent = filter_var($row['sent'] ?? null, \FILTER_VALIDATE_INT);
        $received = filter_var($row['received'] ?? null, \FILTER_VALIDATE_INT);
        $since = is_array($row) && is_string($row['since'] ?? null) ? new \DateTimeImmutable($row['since'])->format(\DATE_ATOM) : null;

        return ['sent' => false === $sent ? 0 : $sent, 'received' => false === $received ? 0 : $received, 'since' => $since];
    }
}
