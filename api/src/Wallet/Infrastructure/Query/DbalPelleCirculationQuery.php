<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Shared\Application\Support\StatsPeriod;
use App\Wallet\Application\Query\PelleCirculationQueryInterface;
use App\Wallet\Domain\Enum\PelleKind;
use Doctrine\DBAL\Connection;

final readonly class DbalPelleCirculationQuery implements PelleCirculationQueryInterface
{
    private const string FLOWS = 'COALESCE(SUM(amount) FILTER (WHERE amount > 0), 0) AS created,
                    COALESCE(-SUM(amount) FILTER (WHERE amount < 0), 0) AS destroyed';

    public function __construct(private Connection $connection)
    {
    }

    public function circulation(StatsPeriod $period): array
    {
        $gold = ['kind' => PelleKind::Gold->value];
        $start = $period->start->format(\DATE_ATOM);
        $end = $period->end->format(\DATE_ATOM);

        $inCirculation = $this->connection->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM pelle_movement WHERE kind = :kind', $gold);

        $bucketRows = $this->connection->fetchAllAssociative(
            "SELECT to_char(date_trunc('{$period->granularity}', created_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS bucket, ".self::FLOWS.'
               FROM pelle_movement
              WHERE kind = :kind AND created_at >= :start AND created_at < :end
              GROUP BY 1',
            $gold + ['start' => $start, 'end' => $end],
        );
        $created = [];
        $destroyed = [];
        foreach ($bucketRows as $row) {
            $bucket = $this->string($row['bucket']);
            $created[$bucket] = $this->int($row['created']);
            $destroyed[$bucket] = $this->int($row['destroyed']);
        }

        $previous = $this->connection->fetchAssociative(
            'SELECT '.self::FLOWS.' FROM pelle_movement WHERE kind = :kind AND created_at >= :previousStart AND created_at < :start',
            $gold + ['previousStart' => $period->previousStart->format(\DATE_ATOM), 'start' => $start],
        );

        $reasonRows = $this->connection->fetchAllAssociative(
            'SELECT reason, '.self::FLOWS.'
               FROM pelle_movement
              WHERE kind = :kind AND created_at >= :start AND created_at < :end
              GROUP BY reason ORDER BY reason',
            $gold + ['start' => $start, 'end' => $end],
        );
        $byReason = [];
        foreach ($reasonRows as $row) {
            $byReason[] = ['reason' => $this->string($row['reason']), 'created' => $this->int($row['created']), 'destroyed' => $this->int($row['destroyed'])];
        }

        return [
            'goldInCirculation' => $this->int($inCirculation),
            'created' => $this->trend($period, $created, $this->int($previous['created'] ?? null)),
            'destroyed' => $this->trend($period, $destroyed, $this->int($previous['destroyed'] ?? null)),
            'byReason' => $byReason,
        ];
    }

    /**
     * @param array<string, int> $values
     *
     * @return array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int}
     */
    private function trend(StatsPeriod $period, array $values, int $previous): array
    {
        $series = $period->series($values);

        return ['series' => $series, 'total' => array_sum(array_column($series, 'value')), 'previous' => $previous];
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
