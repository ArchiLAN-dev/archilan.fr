<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface ActivityEntryRepositoryInterface
{
    public function exists(string $actorId, string $type, string $subjectRef): bool;

    public function save(ActivityEntry $entry): void;

    /**
     * Recent entries by any of the given actors, newest first (feed read, story 30.9).
     *
     * @param list<string> $actorIds
     *
     * @return list<ActivityEntry>
     */
    public function recentForActors(array $actorIds, int $limit): array;
}
