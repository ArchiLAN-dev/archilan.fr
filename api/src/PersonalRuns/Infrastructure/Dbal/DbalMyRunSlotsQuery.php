<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Dbal;

use App\PersonalRuns\Application\Query\MyRunSlotsQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

final readonly class DbalMyRunSlotsQuery implements MyRunSlotsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forMember(string $sessionId, string $userId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('ss.slot_name AS slot_name', 'g.name AS game_name')
            ->from('session_slot', 'ss')
            // Owner and co-players alike: one row per (slot, member) - the shared expression for "who plays".
            ->join('ss', DbalSlotPlayerSource::expression('session_slot', 'registration'), 'sp', $qb->expr()->eq('sp.'.DbalSlotPlayerSource::SLOT_COLUMN, 'ss.id'))
            ->leftJoin('ss', 'game', 'g', $qb->expr()->eq('g.id', 'ss.game_id'))
            ->where($qb->expr()->eq('ss.session_id', ':sessionId'))
            ->andWhere($qb->expr()->eq('sp.'.DbalSlotPlayerSource::USER_COLUMN, ':userId'))
            ->setParameter('sessionId', $sessionId)
            ->setParameter('userId', $userId)
            ->orderBy('ss.slot_order', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $slots = [];
        foreach ($rows as $row) {
            if (!is_string($row['slot_name']) || '' === $row['slot_name']) {
                continue;
            }
            $slots[] = [
                'name' => $row['slot_name'],
                'game' => is_string($row['game_name']) ? $row['game_name'] : null,
            ];
        }

        return $slots;
    }
}
