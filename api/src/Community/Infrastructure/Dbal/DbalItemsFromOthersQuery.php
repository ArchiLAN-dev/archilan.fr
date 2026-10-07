<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\ItemsFromOthersQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

/**
 * Counts the items of the session feed received from another player (story 30.49).
 *
 * The feed names both slots of an item by their names, the link DbalSlotCheckSource already uses; each slot leads to
 * its owner and co-players through DbalSlotPlayerSource. An item counts when the member plays the receiving slot
 * and does not play the sending one. A weekly attempt has no session slot: all of its slots are the member's, so it
 * never counts.
 *
 * Not counted, as nobody found those items:
 *  - what a slot sent from its release (or forfeit) on, and what a slot received from its collect on, both dated on
 *    the slot since this story (two seconds of margin: the announcement can arrive after the first items);
 *  - for the slots released before, a burst of BURST items or more in the same second from one sender (a release)
 *    or to one receiver (a collect): no play moves that many at once.
 */
final readonly class DbalItemsFromOthersQuery implements ItemsFromOthersQueryInterface
{
    public const int BURST = 10;

    public function __construct(private Connection $connection)
    {
    }

    public function count(string $userId): int
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $feed = '(SELECT fe.id, fe.session_id, fe.sender_name, fe.receiver_name, fe.occurred_at,
                         COUNT(*) OVER (PARTITION BY fe.session_id, fe.sender_name, fe.occurred_at) AS burst_out,
                         COUNT(*) OVER (PARTITION BY fe.session_id, fe.receiver_name, fe.occurred_at) AS burst_in
                    FROM session_feed_event fe
                   WHERE fe.type = :itemType)';

        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(DISTINCT f.id)')
            ->from($feed, 'f')
            ->innerJoin('f', 'session_slot', 'rs', 'rs.session_id = f.session_id AND rs.slot_name = f.receiver_name')
            ->innerJoin('rs', $players, 'rp', 'rp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = rs.id')
            ->innerJoin('f', 'session_slot', 'ss', 'ss.session_id = f.session_id AND ss.slot_name = f.sender_name')
            ->where('rp.'.DbalSlotPlayerSource::USER_COLUMN.' = :userId')
            ->andWhere('ss.id <> rs.id')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.$players.' sp WHERE sp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ss.id AND sp.'.DbalSlotPlayerSource::USER_COLUMN.' = :userId)')
            ->andWhere("ss.released_at IS NULL OR f.occurred_at < ss.released_at - INTERVAL '2 seconds'")
            ->andWhere("rs.collected_at IS NULL OR f.occurred_at < rs.collected_at - INTERVAL '2 seconds'")
            ->andWhere('f.burst_out < :burst')
            ->andWhere('f.burst_in < :burst')
            ->setParameter('itemType', 'item-received')
            ->setParameter('userId', $userId)
            ->setParameter('burst', self::BURST)
            ->executeQuery()
            ->fetchOne();

        $count = filter_var($count, \FILTER_VALIDATE_INT);

        return false === $count ? 0 : $count;
    }
}
