<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

/**
 * The draft runs listed for every member (story 43.17), read across the community and game tables.
 */
interface RunListingsQueryInterface
{
    /**
     * Listed draft runs the viewer may see: not their own, no block either way with the owner, not joined yet.
     * `participantIds` are the members in the run, the owner aside. The latest listing first.
     *
     * @return list<array{runId: string, title: string, ownerId: string, pitch: string, plannedFor: \DateTimeImmutable|null, listedAt: \DateTimeImmutable, seatsWanted: int|null, participantIds: list<string>, gameIds: list<string>}>
     */
    public function listingsFor(string $viewerId): array;

    /**
     * @param list<string> $gameIds
     *
     * @return array<string, string> game name keyed by id
     */
    public function gameNames(array $gameIds): array;
}
