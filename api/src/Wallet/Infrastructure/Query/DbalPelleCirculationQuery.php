<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Wallet\Application\Query\PelleCirculationQueryInterface;
use App\Wallet\Domain\Enum\PelleKind;
use Doctrine\DBAL\Connection;

final readonly class DbalPelleCirculationQuery implements PelleCirculationQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function circulation(\DateTimeImmutable $now): array
    {
        $gold = ['kind' => PelleKind::Gold->value];

        $totals = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(amount) FILTER (WHERE amount > 0), 0) AS created,
                    COALESCE(-SUM(amount) FILTER (WHERE amount < 0), 0) AS destroyed
               FROM pelle_movement WHERE kind = :kind',
            $gold,
        );
        $created = $this->int($totals['created'] ?? null);
        $destroyed = $this->int($totals['destroyed'] ?? null);

        // Monday 00:00 UTC of the oldest week shown; weeks without movement are filled with zeros so the
        // chart keeps an even time axis.
        $utcNow = $now->setTimezone(new \DateTimeZone('UTC'));
        $currentWeek = $utcNow->modify('monday this week')->setTime(0, 0);
        $firstWeek = $currentWeek->modify(sprintf('-%d weeks', self::WEEKS - 1));

        $weekRows = $this->connection->fetchAllAssociative(
            "SELECT to_char(date_trunc('week', created_at AT TIME ZONE 'UTC'), 'YYYY-MM-DD') AS week_start,
                    COALESCE(SUM(amount) FILTER (WHERE amount > 0), 0) AS created,
                    COALESCE(-SUM(amount) FILTER (WHERE amount < 0), 0) AS destroyed
               FROM pelle_movement
              WHERE kind = :kind AND created_at >= :since
              GROUP BY 1",
            $gold + ['since' => $firstWeek->format('Y-m-d H:i:sP')],
        );
        $byWeek = [];
        foreach ($weekRows as $row) {
            $byWeek[$this->string($row['week_start'])] = ['created' => $this->int($row['created']), 'destroyed' => $this->int($row['destroyed'])];
        }
        $weeks = [];
        for ($week = $firstWeek; $week <= $currentWeek; $week = $week->modify('+1 week')) {
            $key = $week->format('Y-m-d');
            $weeks[] = ['weekStart' => $key, 'created' => $byWeek[$key]['created'] ?? 0, 'destroyed' => $byWeek[$key]['destroyed'] ?? 0];
        }

        $reasonRows = $this->connection->fetchAllAssociative(
            'SELECT reason,
                    COALESCE(SUM(amount) FILTER (WHERE amount > 0), 0) AS created,
                    COALESCE(-SUM(amount) FILTER (WHERE amount < 0), 0) AS destroyed
               FROM pelle_movement WHERE kind = :kind
              GROUP BY reason ORDER BY reason',
            $gold,
        );
        $byReason = [];
        foreach ($reasonRows as $row) {
            $byReason[] = ['reason' => $this->string($row['reason']), 'created' => $this->int($row['created']), 'destroyed' => $this->int($row['destroyed'])];
        }

        return [
            'goldInCirculation' => $created - $destroyed,
            'created' => $created,
            'destroyed' => $destroyed,
            'weeks' => $weeks,
            'byReason' => $byReason,
        ];
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
