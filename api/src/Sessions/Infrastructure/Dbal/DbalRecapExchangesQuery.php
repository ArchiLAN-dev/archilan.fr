<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Dbal;

use App\Sessions\Application\Query\RecapExchangesQueryInterface;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Story 43.10. The feed names the slots of an item; their players come from DbalSlotPlayerSource (owners and
 * co-players). What a slot sent from its release on, or received from its collect on, is left out (nobody found
 * those, as in DbalItemsFromOthersQuery). A progression item carries Archipelago's advancement flag (bit 1).
 */
final readonly class DbalRecapExchangesQuery implements RecapExchangesQueryInterface
{
    private const int PROGRESSION_FLAG = 1;

    public function __construct(private Connection $connection)
    {
    }

    public function playersBySlot(string $sessionId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('DISTINCT slot.slot_name', 'sp.'.DbalSlotPlayerSource::USER_COLUMN.' AS uid')
            ->from('session_slot', 'slot')
            ->join('slot', DbalSlotPlayerSource::expression('session_slot', 'registration'), 'sp', $qb->expr()->eq('sp.'.DbalSlotPlayerSource::SLOT_COLUMN, 'slot.id'))
            ->where($qb->expr()->eq('slot.session_id', ':sessionId'))
            ->setParameter('sessionId', $sessionId)
            ->orderBy('slot.slot_name')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $players = [];
        foreach ($rows as $row) {
            $slotName = $row['slot_name'] ?? null;
            $uid = $row['uid'] ?? null;
            if (!is_string($slotName) || !is_string($uid)) {
                continue;
            }
            $last = array_key_last($players);
            if (null !== $last && $players[$last]['slotName'] === $slotName) {
                $players[$last]['userIds'][] = $uid;
            } else {
                $players[] = ['slotName' => $slotName, 'userIds' => [$uid]];
            }
        }

        return $players;
    }

    public function exchanges(string $sessionId, array $slotNames): array
    {
        if ([] === $slotNames) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT CASE WHEN fe.sender_name IN (:mine) THEN fe.receiver_name ELSE fe.sender_name END AS other,
                    COUNT(*) FILTER (WHERE fe.sender_name IN (:mine)) AS sent,
                    COUNT(*) FILTER (WHERE fe.receiver_name IN (:mine)) AS received,
                    COUNT(*) FILTER (WHERE fe.sender_name IN (:mine) AND (fe.item_flags & :progression) <> 0) AS sent_progression,
                    COUNT(*) FILTER (WHERE fe.receiver_name IN (:mine) AND (fe.item_flags & :progression) <> 0) AS received_progression
               FROM session_feed_event fe
               JOIN session_slot ss ON ss.session_id = fe.session_id AND ss.slot_name = fe.sender_name
               JOIN session_slot rs ON rs.session_id = fe.session_id AND rs.slot_name = fe.receiver_name
              WHERE fe.session_id = :sessionId
                AND fe.type = :itemType
                AND ((fe.sender_name IN (:mine)) <> (fe.receiver_name IN (:mine)))
                AND (ss.released_at IS NULL OR fe.occurred_at < ss.released_at - INTERVAL '2 seconds')
                AND (rs.collected_at IS NULL OR fe.occurred_at < rs.collected_at - INTERVAL '2 seconds')
              GROUP BY other",
            ['sessionId' => $sessionId, 'mine' => $slotNames, 'itemType' => SessionFeedEvent::TYPE_ITEM_RECEIVED, 'progression' => self::PROGRESSION_FLAG],
            ['mine' => ArrayParameterType::STRING],
        );

        $exchanges = [];
        foreach ($rows as $row) {
            $other = $row['other'] ?? null;
            if (!is_string($other)) {
                continue;
            }
            $exchanges[] = [
                'slotName' => $other,
                'sent' => self::int($row['sent'] ?? null),
                'received' => self::int($row['received'] ?? null),
                'sentProgression' => self::int($row['sent_progression'] ?? null),
                'receivedProgression' => self::int($row['received_progression'] ?? null),
            ];
        }

        return $exchanges;
    }

    public function unblockingItems(string $sessionId, array $slotNames, int $windowSeconds): array
    {
        if ([] === $slotNames) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.slot_name, fe.item_name, fe.sender_name, fe.occurred_at
               FROM session_slot_block_release r
               JOIN LATERAL (
                    SELECT f.item_name, f.sender_name, f.occurred_at
                      FROM session_feed_event f
                     WHERE f.session_id = r.session_id
                       AND f.type = :itemType
                       AND f.receiver_name = r.slot_name
                       AND f.sender_name NOT IN (:mine)
                       AND (f.item_flags & :progression) <> 0
                       AND f.occurred_at >= GREATEST(r.blocked_since, r.released_at - make_interval(secs => :window))
                       AND f.occurred_at <= r.released_at
                     ORDER BY f.occurred_at DESC
                     LIMIT 1
               ) fe ON TRUE
              WHERE r.session_id = :sessionId
                AND r.slot_name IN (:mine)
              ORDER BY r.released_at',
            ['sessionId' => $sessionId, 'mine' => $slotNames, 'itemType' => SessionFeedEvent::TYPE_ITEM_RECEIVED, 'progression' => self::PROGRESSION_FLAG, 'window' => $windowSeconds],
            ['mine' => ArrayParameterType::STRING],
        );

        $moments = [];
        foreach ($rows as $row) {
            $slotName = $row['slot_name'] ?? null;
            $senderName = $row['sender_name'] ?? null;
            $at = $row['occurred_at'] ?? null;
            if (!is_string($slotName) || !is_string($senderName) || !is_string($at)) {
                continue;
            }
            $moments[] = [
                'slotName' => $slotName,
                'itemName' => is_string($row['item_name'] ?? null) ? $row['item_name'] : null,
                'senderName' => $senderName,
                'at' => new \DateTimeImmutable($at)->format(\DATE_ATOM),
            ];
        }

        return $moments;
    }

    private static function int(mixed $value): int
    {
        $int = filter_var($value, \FILTER_VALIDATE_INT);

        return false === $int ? 0 : $int;
    }
}
