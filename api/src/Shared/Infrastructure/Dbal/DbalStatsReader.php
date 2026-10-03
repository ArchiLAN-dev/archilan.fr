<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Dbal;

use App\Shared\Application\Support\StatsPeriod;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The SQL side of the admin statistics sections (stories 42.1, 42.2): counts per bucket of a {@see StatsPeriod},
 * with the totals of the period and of the one before. Each section only says what it counts.
 *
 * `$from` is a SQL fragment starting at FROM and ending with a WHERE clause (use `WHERE 1 = 1` when there is
 * nothing to filter); the date bounds are appended to it.
 */
final readonly class DbalStatsReader
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * Rows counted by bucket of their date (`COUNT(*)`), or distinct values counted by bucket (`COUNT(DISTINCT
     * expr)`) when `$distinct` is given: then the total is distinct over the whole period, not a sum of buckets.
     *
     * @param array<string, mixed>                      $params
     * @param array<string, ArrayParameterType::STRING> $types
     *
     * @return array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int}
     */
    public function trend(StatsPeriod $period, string $from, string $dateColumn, array $params = [], array $types = [], ?string $distinct = null): array
    {
        $measure = null === $distinct ? 'COUNT(*)' : "COUNT(DISTINCT {$distinct})";
        $start = $period->start->format(\DATE_ATOM);
        $end = $period->end->format(\DATE_ATOM);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT to_char(date_trunc('{$period->granularity}', {$dateColumn} AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS bucket, {$measure} AS n
               {$from} AND {$dateColumn} >= :statsFrom AND {$dateColumn} < :statsTo
              GROUP BY 1",
            $params + ['statsFrom' => $start, 'statsTo' => $end],
            $types,
        );
        $values = [];
        foreach ($rows as $row) {
            $bucket = $row['bucket'] ?? null;
            if (is_string($bucket)) {
                $values[$bucket] = $this->int($row['n'] ?? null);
            }
        }
        $series = $period->series($values);

        $between = fn (string $from_, string $to): int => $this->count(
            "SELECT {$measure} {$from} AND {$dateColumn} >= :statsFrom AND {$dateColumn} < :statsTo",
            $params + ['statsFrom' => $from_, 'statsTo' => $to],
            $types,
        );

        return [
            'series' => $series,
            'total' => null === $distinct ? array_sum(array_column($series, 'value')) : $between($start, $end),
            'previous' => $between($period->previousStart->format(\DATE_ATOM), $start),
        ];
    }

    /**
     * @param array<string, mixed>                      $params
     * @param array<string, ArrayParameterType::STRING> $types
     */
    public function count(string $sql, array $params = [], array $types = []): int
    {
        return $this->int($this->connection->fetchOne($sql, $params, $types));
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
