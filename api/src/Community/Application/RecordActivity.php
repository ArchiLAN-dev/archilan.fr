<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ActivityEntry;
use App\Community\Domain\ActivityEntryRepositoryInterface;

/**
 * Idempotent append to the activity feed. The `(actor, type, subjectRef)` uniqueness means a backfill and
 * a live signal can both target the same fact without creating a duplicate (epic §E).
 */
final readonly class RecordActivity
{
    public function __construct(private ActivityEntryRepositoryInterface $entries)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return bool true if a new entry was recorded, false if it already existed
     */
    public function record(string $actorId, string $type, string $subjectRef, \DateTimeImmutable $occurredAt, array $payload = []): bool
    {
        if ($this->entries->exists($actorId, $type, $subjectRef)) {
            return false;
        }

        $this->entries->save(ActivityEntry::record($actorId, $type, $subjectRef, $occurredAt, $payload));

        return true;
    }
}
