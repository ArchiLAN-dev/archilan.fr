<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Query;

/**
 * The draft runs the viewer's friends opened to them (story 43.14), read across the community tables so
 * PersonalRuns does not import the Community domain.
 */
interface FriendsOpenRunsQueryInterface
{
    /**
     * Draft runs open to friends whose owner is an accepted friend of the viewer, with no block either way, and
     * that the viewer has not joined yet. `joined` counts the members in the run, the owner aside. Latest first.
     *
     * @return list<array{runId: string, title: string, ownerId: string, seatsWanted: int|null, joined: int, createdAt: \DateTimeImmutable}>
     */
    public function openRunsFor(string $viewerId): array;
}
