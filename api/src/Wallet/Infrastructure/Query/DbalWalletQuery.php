<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Wallet\Application\Query\WalletQueryInterface;
use App\Wallet\Domain\Enum\PelleKind;
use Doctrine\DBAL\Connection;

final readonly class DbalWalletQuery implements WalletQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function walletOf(string $userId, int $page): array
    {
        $page = max(1, $page);

        $gold = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM pelle_movement WHERE user_id = :userId AND kind = :kind',
            ['userId' => $userId, 'kind' => PelleKind::Gold->value],
        );

        $eventRows = $this->connection->fetchAllAssociative(
            'SELECT m.event_id, e.title, SUM(m.amount) AS balance
               FROM pelle_movement m
               LEFT JOIN event e ON e.id = m.event_id
              WHERE m.user_id = :userId AND m.kind = :kind
              GROUP BY m.event_id, e.title
             HAVING SUM(m.amount) <> 0
              ORDER BY e.title',
            ['userId' => $userId, 'kind' => PelleKind::Event->value],
        );
        $events = [];
        foreach ($eventRows as $row) {
            $events[] = [
                'eventId' => $this->string($row['event_id']),
                'eventTitle' => $this->string($row['title']),
                'balance' => $this->int($row['balance']),
            ];
        }

        $total = $this->connection->fetchOne('SELECT COUNT(*) FROM pelle_movement WHERE user_id = :userId', ['userId' => $userId]);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id, m.amount, m.kind, m.event_id, e.title, m.reason, m.label, m.created_at
               FROM pelle_movement m
               LEFT JOIN event e ON e.id = m.event_id
              WHERE m.user_id = :userId
              ORDER BY m.created_at DESC, m.id DESC
              LIMIT :limit OFFSET :offset',
            ['userId' => $userId, 'limit' => self::PER_PAGE, 'offset' => ($page - 1) * self::PER_PAGE],
            ['limit' => \Doctrine\DBAL\ParameterType::INTEGER, 'offset' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
        $items = [];
        foreach ($rows as $row) {
            $eventId = null === $row['event_id'] ? null : $this->string($row['event_id']);
            $items[] = [
                'id' => $this->string($row['id']),
                'amount' => $this->int($row['amount']),
                'kind' => $this->string($row['kind']),
                'eventId' => $eventId,
                'eventTitle' => null === $row['title'] ? null : $this->string($row['title']),
                'reason' => $this->string($row['reason']),
                'label' => $this->string($row['label']),
                'createdAt' => new \DateTimeImmutable($this->string($row['created_at']))->format(\DATE_ATOM),
            ];
        }

        return [
            'gold' => $this->int($gold),
            'events' => $events,
            'history' => [
                'items' => $items,
                'page' => $page,
                'perPage' => self::PER_PAGE,
                'total' => $this->int($total),
            ],
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
