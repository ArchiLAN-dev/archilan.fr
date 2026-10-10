<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Dbal;

use App\PersonalRuns\Application\Query\RunPlayerActivityQueryInterface;
use App\PersonalRuns\Application\Query\RunPlayersActivity;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

final readonly class DbalRunPlayerActivityQuery implements RunPlayerActivityQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forSession(string $sessionId): RunPlayersActivity
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'p.'.DbalSlotPlayerSource::USER_COLUMN.' AS user_id',
                'MAX(ss.last_check_at) AS last_check_at',
                'BOOL_AND(ss.was_released OR ss.goal_reached_at IS NOT NULL) AS finished',
                'MAX(s.started_at) AS started_at',
            )
            ->from('session_slot', 'ss')
            ->join('ss', 'session', 's', $qb->expr()->eq('s.id', 'ss.session_id'))
            ->join('ss', DbalSlotPlayerSource::expression('session_slot', 'registration'), 'p', 'p.'.DbalSlotPlayerSource::SLOT_COLUMN.' = ss.id')
            ->where($qb->expr()->eq('ss.session_id', ':session'))
            ->groupBy('p.'.DbalSlotPlayerSource::USER_COLUMN)
            ->setParameter('session', $sessionId)
            ->executeQuery()
            ->fetchAllAssociative();

        $startedAt = null;
        $players = [];
        foreach ($rows as $row) {
            $userId = $row['user_id'];
            if (!is_string($userId)) {
                continue;
            }
            $startedAt ??= self::instant($row['started_at']);
            $players[$userId] = [
                'lastCheckAt' => self::instant($row['last_check_at']),
                'finished' => true === $row['finished'],
            ];
        }

        return new RunPlayersActivity($startedAt, $players);
    }

    public function blockedBetween(string $userId, string $otherId): bool
    {
        $qb = $this->connection->createQueryBuilder();

        return false !== $qb
            ->select('1')
            ->from('community_block', 'b')
            ->where('(b.blocker_id = :a AND b.blocked_id = :b) OR (b.blocker_id = :b AND b.blocked_id = :a)')
            ->setParameter('a', $userId)
            ->setParameter('b', $otherId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
