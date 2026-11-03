<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Registrations\Domain\Entity\Registration;
use App\Wallet\Application\Query\MinimapperzCodesQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalMinimapperzCodesQuery implements MinimapperzCodesQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function codes(string $eventId): array
    {
        $user = $this->connection->quoteSingleIdentifier('user');
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.code, c.user_id, c.assigned_at, u.display_name,
                    c.user_id IN (SELECT r.user_id FROM registration r WHERE r.event_id = :eventId AND r.status <> :cancelled) AS registered
               FROM minimapperz_code c
               LEFT JOIN {$user} u ON u.id = c.user_id
              WHERE c.event_id = :eventId
              ORDER BY c.user_id IS NULL, lower(u.display_name), c.imported_at, c.code",
            ['eventId' => $eventId, 'cancelled' => Registration::STATUS_CANCELLED],
        );

        $codes = [];
        foreach ($rows as $row) {
            $assignedAt = is_string($row['assigned_at']) ? new \DateTimeImmutable($row['assigned_at']) : null;
            $codes[] = [
                'code' => is_scalar($row['code']) ? (string) $row['code'] : '',
                'userId' => is_string($row['user_id']) ? $row['user_id'] : null,
                'displayName' => is_string($row['display_name']) ? $row['display_name'] : null,
                'registered' => true === $row['registered'],
                'assignedAt' => $assignedAt?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            ];
        }

        return $codes;
    }

    public function registrantsWithoutCode(string $eventId): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT r.user_id FROM registration r
              WHERE r.event_id = :eventId AND r.status <> :cancelled
                AND NOT EXISTS (SELECT 1 FROM minimapperz_code c WHERE c.event_id = r.event_id AND c.user_id = r.user_id)
              ORDER BY r.user_id',
            ['eventId' => $eventId, 'cancelled' => Registration::STATUS_CANCELLED],
        );

        return array_values(array_filter($ids, is_string(...)));
    }
}
