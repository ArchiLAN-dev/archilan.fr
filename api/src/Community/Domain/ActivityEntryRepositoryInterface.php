<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface ActivityEntryRepositoryInterface
{
    public function exists(string $actorId, string $type, string $subjectRef): bool;

    /**
     * The id of the actor who owns the entry, or null if no entry has that id.
     */
    public function ownerOf(string $entryId): ?string;

    public function save(ActivityEntry $entry): void;

    /**
     * Recent entries by any of the given actors, newest first (feed read, story 30.9). `$before` paginates
     * by returning only entries strictly older than the given instant.
     *
     * @param list<string> $actorIds
     *
     * @return list<ActivityEntry>
     */
    public function recentForActors(array $actorIds, int $limit, ?\DateTimeImmutable $before = null): array;
}
