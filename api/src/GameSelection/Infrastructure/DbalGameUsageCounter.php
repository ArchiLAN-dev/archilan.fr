<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Application\GameUsageCounterInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalGameUsageCounter implements GameUsageCounterInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function count(string $gameId): int
    {
        return $this->countIn('session_slot', $gameId) + $this->countIn('weekly_templates', $gameId);
    }

    private function countIn(string $table, string $gameId): int
    {
        $qb = $this->connection->createQueryBuilder();
        $result = $qb->select('COUNT(*)')
            ->from($table)
            ->where($qb->expr()->eq('game_id', ':gameId'))
            ->setParameter('gameId', $gameId)
            ->executeQuery()
            ->fetchOne();

        return false !== $result && is_numeric($result) ? (int) $result : 0;
    }
}
