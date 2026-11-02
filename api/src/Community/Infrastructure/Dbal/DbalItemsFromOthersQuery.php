<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\ItemsFromOthersQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalFoundItemSource;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

/**
 * Counts the items of the session feed received from another player (story 30.49).
 *
 * The feed names both slots of an item by their names, the link DbalSlotCheckSource already uses; each slot leads to
 * its owner and co-players through DbalSlotPlayerSource. An item counts when the member plays the receiving slot
 * and does not play the sending one. A weekly attempt has no session slot: all of its slots are the member's, so it
 * never counts. Released and collected items are dropped by the rules of {@see DbalFoundItemSource}, which the
 * quests on items traded share (story 41.64).
 */
final readonly class DbalItemsFromOthersQuery implements ItemsFromOthersQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function count(string $userId): int
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $feed = DbalFoundItemSource::feed();

        $count = $this->connection->createQueryBuilder()
            ->select('COUNT(DISTINCT f.id)')
            ->from($feed, 'f')
            ->innerJoin('f', 'session_slot', 'rs', 'rs.session_id = f.session_id AND rs.slot_name = f.receiver_name')
            ->innerJoin('rs', $players, 'rp', 'rp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = rs.id')
            ->innerJoin('f', 'session_slot', 'ss', 'ss.session_id = f.session_id AND ss.slot_name = f.sender_name')
            ->where('rp.'.DbalSlotPlayerSource::USER_COLUMN.' = :userId')
            ->andWhere('ss.id <> rs.id')
            ->andWhere('NOT EXISTS (SELECT 1 FROM '.$players.' sp WHERE sp.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ss.id AND sp.'.DbalSlotPlayerSource::USER_COLUMN.' = :userId)')
            ->andWhere(...array_map(static fn (string $rule): string => '('.$rule.')', DbalFoundItemSource::found('f', 'ss', 'rs')))
            ->setParameter('itemType', DbalFoundItemSource::ITEM_TYPE)
            ->setParameter('userId', $userId)
            ->setParameter('burst', DbalFoundItemSource::BURST)
            ->executeQuery()
            ->fetchOne();

        $count = filter_var($count, \FILTER_VALIDATE_INT);

        return false === $count ? 0 : $count;
    }
}
