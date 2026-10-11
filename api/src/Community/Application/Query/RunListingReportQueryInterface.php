<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

/**
 * The run listings a member may report and the moderators read (story 43.17), from the personal runs' table so
 * Community does not import the PersonalRuns domain.
 */
interface RunListingReportQueryInterface
{
    /**
     * The run while it is listed for every member, or null.
     *
     * @return array{runId: string, title: string, pitch: string|null, ownerId: string}|null
     */
    public function listed(string $runId): ?array;

    /**
     * The runs as they stand now, listed or not (a listing taken down has no message any more).
     *
     * @param list<string> $runIds
     *
     * @return array<string, array{runId: string, title: string, pitch: string|null, ownerId: string}> keyed by run id
     */
    public function byIds(array $runIds): array;
}
