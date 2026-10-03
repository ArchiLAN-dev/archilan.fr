<?php

declare(strict_types=1);

namespace App\Events\Infrastructure\Dbal;

use App\Events\Application\Query\EventStatsQueryInterface;
use App\Events\Domain\Entity\Event;
use App\Payments\Application\Support\HelloAssoConfig;
use App\Registrations\Domain\Entity\Registration;
use App\Shared\Application\Support\StatsPeriod;
use App\Shared\Infrastructure\Dbal\DbalStatsReader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads the Events section of the admin statistics page (story 42.3). Like the admin dashboard counters
 * (DbalDashboardStatsQuery), it reads registrations and HelloAsso orders in plain SQL.
 */
final readonly class DbalEventStatsQuery implements EventStatsQueryInterface
{
    public function __construct(
        private Connection $connection,
        private DbalStatsReader $reader,
    ) {
    }

    public function stats(StatsPeriod $period, \DateTimeImmutable $now): array
    {
        $paid = 'FROM hello_asso_order WHERE paid_at IS NOT NULL';
        $revenueOf = fn (string $formType): array => $this->reader->trend(
            $period,
            $paid.' AND form_type = :formType',
            'paid_at',
            ['formType' => $formType],
            sum: 'amount_cents',
        );

        return [
            'upcomingEvents' => $this->reader->count(
                'SELECT COUNT(*) FROM event WHERE status IN (:statuses) AND starts_at > :now',
                ['statuses' => Event::PUBLIC_STATUSES, 'now' => $now->format(\DATE_ATOM)],
                ['statuses' => ArrayParameterType::STRING],
            ),
            'registrations' => $this->reader->trend($period, 'FROM registration WHERE 1 = 1', 'created_at'),
            'cancellations' => $this->reader->trend(
                $period,
                'FROM registration WHERE status = :cancelled',
                'updated_at',
                ['cancelled' => Registration::STATUS_CANCELLED],
            ),
            'revenue' => $this->reader->trend($period, $paid, 'paid_at', sum: 'amount_cents'),
            'revenueByType' => [
                'events' => $revenueOf(HelloAssoConfig::FORM_TYPE_EVENT),
                'memberships' => $revenueOf(HelloAssoConfig::FORM_TYPE_MEMBERSHIP),
                'shop' => $revenueOf(HelloAssoConfig::FORM_TYPE_SHOP),
            ],
            'events' => $this->eventsOf($period),
        ];
    }

    /**
     * @return list<array{eventId: string, title: string, startsAt: string, status: string, capacity: int, registrations: int, fillRate: int}>
     */
    private function eventsOf(StatsPeriod $period): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.title, e.starts_at, e.status, e.capacity,
                    (SELECT COUNT(*) FROM registration r WHERE r.event_id = e.id AND r.status <> :cancelled) AS registrations
               FROM event e
              WHERE e.starts_at >= :start AND e.starts_at < :end
              ORDER BY e.starts_at DESC',
            [
                'cancelled' => Registration::STATUS_CANCELLED,
                'start' => $period->start->format(\DATE_ATOM),
                'end' => $period->end->format(\DATE_ATOM),
            ],
        );

        $events = [];
        foreach ($rows as $row) {
            $capacity = is_numeric($row['capacity']) ? (int) $row['capacity'] : 0;
            $registrations = is_numeric($row['registrations']) ? (int) $row['registrations'] : 0;
            $events[] = [
                'eventId' => is_scalar($row['id']) ? (string) $row['id'] : '',
                'title' => is_scalar($row['title']) ? (string) $row['title'] : '',
                'startsAt' => new \DateTimeImmutable(is_scalar($row['starts_at']) ? (string) $row['starts_at'] : 'now')->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
                'status' => is_scalar($row['status']) ? (string) $row['status'] : '',
                'capacity' => $capacity,
                'registrations' => $registrations,
                'fillRate' => $capacity > 0 ? intdiv($registrations * 100, $capacity) : 0,
            ];
        }

        return $events;
    }
}
