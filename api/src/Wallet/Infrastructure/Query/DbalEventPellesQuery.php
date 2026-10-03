<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Registrations\Domain\Entity\Registration;
use App\Wallet\Application\Query\EventPellesQueryInterface;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use Doctrine\DBAL\Connection;

final readonly class DbalEventPellesQuery implements EventPellesQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function activeRegistrantIds(string $eventId): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT user_id FROM registration WHERE event_id = :eventId AND status <> :cancelled ORDER BY user_id',
            ['eventId' => $eventId, 'cancelled' => Registration::STATUS_CANCELLED],
        );

        return array_map($this->string(...), $ids);
    }

    public function endedEventBalances(\DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.event_id, e.title, m.user_id, SUM(m.amount) AS balance
               FROM pelle_movement m
               JOIN event e ON e.id = m.event_id
              WHERE m.kind = :kind AND e.ends_at <= :now
              GROUP BY m.event_id, e.title, m.user_id
             HAVING SUM(m.amount) > 0
              ORDER BY m.event_id, m.user_id',
            ['kind' => PelleKind::Event->value, 'now' => $now->format(\DATE_ATOM)],
        );

        $balances = [];
        foreach ($rows as $row) {
            $balances[] = [
                'eventId' => $this->string($row['event_id']),
                'eventTitle' => $this->string($row['title']),
                'userId' => $this->string($row['user_id']),
                'balance' => $this->int($row['balance']),
            ];
        }

        return $balances;
    }

    public function eventPage(string $eventId, \DateTimeImmutable $now): ?array
    {
        $event = $this->connection->fetchAssociative('SELECT id, title, ends_at FROM event WHERE id = :id', ['id' => $eventId]);
        if (false === $event) {
            return null;
        }

        $flows = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(amount) FILTER (WHERE reason = :distribution), 0) AS distributed,
                    COALESCE(SUM(amount), 0) AS in_circulation,
                    COALESCE(-SUM(amount) FILTER (WHERE reason = :expired), 0) AS destroyed
               FROM pelle_movement WHERE kind = :kind AND event_id = :eventId',
            [
                'distribution' => PelleReason::EventDistribution->value,
                'expired' => PelleReason::EventExpired->value,
                'kind' => PelleKind::Event->value,
                'eventId' => $eventId,
            ],
        );
        // A conversion is a gold line: it is found by its key, which names the event.
        $converted = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM pelle_movement WHERE reason = :conversion AND unique_key LIKE :prefix',
            ['conversion' => PelleReason::EventConversion->value, 'prefix' => 'event-conversion:'.$eventId.':%'],
        );

        $user = $this->connection->quoteSingleIdentifier('user');
        $rows = $this->connection->fetchAllAssociative(
            "SELECT u.id, u.display_name, u.banned_at,
                    COALESCE((SELECT SUM(m.amount) FROM pelle_movement m
                               WHERE m.user_id = u.id AND m.kind = :kind AND m.event_id = :eventId), 0) AS balance
               FROM {$user} u
              WHERE u.id IN (SELECT r.user_id FROM registration r WHERE r.event_id = :eventId AND r.status <> :cancelled)
              ORDER BY lower(u.display_name), u.id",
            ['kind' => PelleKind::Event->value, 'eventId' => $eventId, 'cancelled' => Registration::STATUS_CANCELLED],
        );
        $participants = [];
        foreach ($rows as $row) {
            $participants[] = [
                'userId' => $this->string($row['id']),
                'displayName' => $this->string($row['display_name']),
                'balance' => $this->int($row['balance']),
                'banned' => null !== $row['banned_at'],
            ];
        }

        $endsAt = new \DateTimeImmutable($this->string($event['ends_at']));

        return [
            'eventId' => $this->string($event['id']),
            'eventTitle' => $this->string($event['title']),
            'endsAt' => $endsAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            'ended' => $endsAt <= $now,
            'distributed' => $this->int($flows['distributed'] ?? null),
            'inCirculation' => $this->int($flows['in_circulation'] ?? null),
            'converted' => $this->int($converted),
            'destroyed' => $this->int($flows['destroyed'] ?? null),
            'participants' => $participants,
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
