<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Shared\Application\Support\StatsPeriod;

interface PelleCirculationQueryInterface
{
    /**
     * The gold pelles economy over a period (stories 41.1 AC8, 42.1 AC8): what is in circulation now, what was
     * created (credits) and destroyed (spends) per bucket with the totals of the period and of the one before,
     * and the same split per reason over the period. Event pelles stay out: they are bound to one event and do
     * not make the site's money supply. Neither do the bronze pelles of the private runs (story 41.38): they have
     * their own line - earned, spent (net of refunds) and burned (net of restorations) over the period.
     *
     * @return array{
     *     goldInCirculation: int,
     *     created: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     destroyed: array{series: list<array{start: string, value: int, current: bool}>, total: int, previous: int},
     *     byReason: list<array{reason: string, created: int, destroyed: int}>,
     *     bronze: array{created: int, spent: int, burned: int}
     * }
     */
    public function circulation(StatsPeriod $period): array;
}
