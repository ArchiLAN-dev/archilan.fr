<?php

declare(strict_types=1);

namespace App\Events\Application\Query;

use App\Shared\Application\Support\StatsPeriod;

interface EventStatsQueryInterface
{
    /**
     * The Events section of the admin statistics page (story 42.3). Only counts, amounts and event titles.
     *
     * - registrations: by creation date, cancelled ones included (the urge to register).
     * - cancellations: cancelled registrations, dated by their last update (the cancellation date is not kept
     *   apart).
     * - revenue / revenueByType: paid HelloAsso orders in cents, by payment date, in total and per form type.
     * - events: the events that start in the period, newest first, with their active registrations and their
     *   filling rate (percent of the capacity, rounded down).
     *
     * @return array{
     *     upcomingEvents: int,
     *     registrations: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     cancellations: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     revenue: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     revenueByType: array{
     *         events: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *         memberships: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *         shop: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int}
     *     },
     *     events: list<array{eventId: string, title: string, startsAt: string, status: string, capacity: int, registrations: int, fillRate: int}>
     * }
     */
    public function stats(StatsPeriod $period, \DateTimeImmutable $now): array;
}
