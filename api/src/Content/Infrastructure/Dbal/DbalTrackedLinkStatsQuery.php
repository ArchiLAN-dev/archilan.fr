<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Dbal;

use App\Content\Application\Query\TrackedLinkStatsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalTrackedLinkStatsQuery implements TrackedLinkStatsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function totals(string $since7, string $since30): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'v.link_id',
                'SUM(v.visits) AS total',
                'SUM(CASE WHEN v.day >= :since7 THEN v.visits ELSE 0 END) AS last7',
                'SUM(CASE WHEN v.day >= :since30 THEN v.visits ELSE 0 END) AS last30',
            )
            ->from('tracked_link_visit', 'v')
            ->groupBy('v.link_id')
            ->setParameter('since7', $since7)
            ->setParameter('since30', $since30)
            ->executeQuery()
            ->fetchAllAssociative();

        $totals = [];
        foreach ($rows as $row) {
            if (is_string($row['link_id'] ?? null)) {
                $totals[$row['link_id']] = ['total' => self::int($row['total'] ?? null), 'last7' => self::int($row['last7'] ?? null), 'last30' => self::int($row['last30'] ?? null)];
            }
        }

        return $totals;
    }

    public function daily(string $linkId, string $since): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('v.day', 'SUM(v.visits) AS visits')
            ->from('tracked_link_visit', 'v')
            ->where($qb->expr()->eq('v.link_id', ':link'))
            ->andWhere($qb->expr()->gte('v.day', ':since'))
            ->groupBy('v.day')
            ->setParameter('link', $linkId)
            ->setParameter('since', $since)
            ->executeQuery()
            ->fetchAllAssociative();

        $days = [];
        foreach ($rows as $row) {
            if (is_string($row['day'] ?? null)) {
                $days[$row['day']] = self::int($row['visits'] ?? null);
            }
        }

        return $days;
    }

    public function origins(string $linkId, string $since, int $limit): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('v.referrer_host', 'SUM(v.visits) AS visits')
            ->from('tracked_link_visit', 'v')
            ->where($qb->expr()->eq('v.link_id', ':link'))
            ->andWhere($qb->expr()->gte('v.day', ':since'))
            ->groupBy('v.referrer_host')
            ->orderBy('visits', 'DESC')
            ->addOrderBy('v.referrer_host', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('link', $linkId)
            ->setParameter('since', $since)
            ->executeQuery()
            ->fetchAllAssociative();

        $origins = [];
        foreach ($rows as $row) {
            if (is_string($row['referrer_host'] ?? null)) {
                $origins[] = ['host' => $row['referrer_host'], 'visits' => self::int($row['visits'] ?? null)];
            }
        }

        return $origins;
    }

    /** Postgres gives a SUM as a numeric string. */
    private static function int(mixed $value): int
    {
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : 0;
    }
}
