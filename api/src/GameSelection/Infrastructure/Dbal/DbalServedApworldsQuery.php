<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Dbal;

use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalServedApworldsQuery implements ServedApworldsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function servedApworlds(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb->select('g.id', 'g.apworld_hash', 'g.disabled_at')
            ->from('game', 'g')
            ->where($qb->expr()->isNotNull('g.apworld_hash'))
            ->andWhere($qb->expr()->neq('g.apworld_hash', ':empty'))
            ->setParameter('empty', '')
            ->orderBy('g.id')
            ->executeQuery()
            ->fetchAllAssociative();

        $served = [];
        foreach ($rows as $row) {
            if (is_string($row['id']) && is_string($row['apworld_hash'])) {
                $served[] = new ServedApworld($row['id'], $row['apworld_hash'], null !== $row['disabled_at']);
            }
        }

        return $served;
    }
}
