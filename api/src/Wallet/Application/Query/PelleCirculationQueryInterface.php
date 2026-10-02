<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

interface PelleCirculationQueryInterface
{
    public const int WEEKS = 12;

    /**
     * The gold pelles economy at a glance (story 41.1 AC8): what is in circulation, what was created
     * (credits) and destroyed (spends) overall, per week over the last weeks and per reason. Event pelles
     * stay out: they are bound to one event and do not make the site's money supply.
     *
     * @return array{
     *     goldInCirculation: int,
     *     created: int,
     *     destroyed: int,
     *     weeks: list<array{weekStart: string, created: int, destroyed: int}>,
     *     byReason: list<array{reason: string, created: int, destroyed: int}>
     * }
     */
    public function circulation(\DateTimeImmutable $now): array;
}
