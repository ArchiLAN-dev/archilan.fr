<?php

declare(strict_types=1);

namespace App\Content\Application\Query;

/** Story 55.2: the visits counted on the tracked links, read from the daily counts. Days are `Y-m-d`, in Paris. */
interface TrackedLinkStatsQueryInterface
{
    /**
     * Per link that has visits: all of them, and those since each given day (included).
     *
     * @return array<string, array{total: int, last7: int, last30: int}> by link id
     */
    public function totals(string $since7, string $since30): array;

    /** @return array<string, int> visits by day, from `$since` (included); days without a visit are absent */
    public function daily(string $linkId, string $since): array;

    /** @return list<array{host: string, visits: int}> from `$since` (included), most visits first */
    public function origins(string $linkId, string $since, int $limit): array;
}
