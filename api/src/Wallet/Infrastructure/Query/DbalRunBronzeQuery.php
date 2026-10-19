<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Wallet\Application\Query\RunBronzeQueryInterface;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use Doctrine\DBAL\Connection;

final readonly class DbalRunBronzeQuery implements RunBronzeQueryInterface
{
    private const int HISTORY_LIMIT = 100;

    public function __construct(private Connection $connection)
    {
    }

    public function ofMember(string $userId, string $runId): array
    {
        $params = ['userId' => $userId, 'runId' => $runId, 'kind' => PelleKind::Bronze->value, 'check' => PelleReason::BronzeCheck->value];

        $balance = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM pelle_movement WHERE user_id = :userId AND run_id = :runId AND kind = :kind',
            $params,
        );

        // Checks grouped by day, every other movement on its own line.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT reason, label, amount, count, at FROM (
                SELECT m.reason, 'Checks' AS label, SUM(m.amount) AS amount, COUNT(*) AS count, MAX(m.created_at) AS at
                  FROM pelle_movement m
                 WHERE m.user_id = :userId AND m.run_id = :runId AND m.kind = :kind AND m.reason = :check
                 GROUP BY m.reason, DATE(m.created_at)
                UNION ALL
                SELECT m.reason, m.label, m.amount, 1 AS count, m.created_at AS at
                  FROM pelle_movement m
                 WHERE m.user_id = :userId AND m.run_id = :runId AND m.kind = :kind AND m.reason <> :check
             ) lines
             ORDER BY at DESC
             LIMIT ".self::HISTORY_LIMIT,
            $params,
        );

        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'reason' => is_string($row['reason']) ? $row['reason'] : '',
                'label' => is_string($row['label']) ? $row['label'] : '',
                'amount' => is_numeric($row['amount']) ? (int) $row['amount'] : 0,
                'count' => is_numeric($row['count']) ? (int) $row['count'] : 1,
                'at' => is_string($row['at']) ? new \DateTimeImmutable($row['at'])->format(\DATE_ATOM) : '',
            ];
        }

        return ['balance' => is_numeric($balance) ? (int) $balance : 0, 'history' => $history];
    }
}
