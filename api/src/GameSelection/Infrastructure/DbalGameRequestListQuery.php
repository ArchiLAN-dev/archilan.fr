<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Application\GameRequestListQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalGameRequestListQuery implements GameRequestListQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function list(?string $userId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select(
            'gr.normalized_name',
            'MIN(gr.game_name) AS display_name',
            'COUNT(*) AS vote_count',
        )
            ->from('game_request', 'gr')
            ->groupBy('gr.normalized_name')
            ->orderBy('vote_count', 'DESC')
            ->addOrderBy('gr.normalized_name', 'ASC')
            ->setMaxResults(50);

        if (null !== $userId) {
            $qb->addSelect('BOOL_OR(gr.user_id = :userId) AS has_voted')
                ->setParameter('userId', $userId);
        } else {
            $qb->addSelect('FALSE AS has_voted');
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'normalizedName' => is_string($row['normalized_name'] ?? null) ? $row['normalized_name'] : '',
            'displayName' => is_string($row['display_name'] ?? null) ? $row['display_name'] : '',
            'voteCount' => is_numeric($row['vote_count'] ?? null) ? (int) $row['vote_count'] : 0,
            'hasVoted' => (bool) ($row['has_voted'] ?? false),
        ], $rows);
    }
}
