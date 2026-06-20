<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\BackfillActivity;
use App\Community\Application\CommunityUserIdsQueryInterface;
use App\Community\Application\RecordActivity;
use App\Community\Domain\ActivityEntry;
use App\Community\Domain\ActivityEntryRepositoryInterface;
use App\Identity\Application\PlayerHistoryQueryInterface;
use PHPUnit\Framework\TestCase;

final class ActivityFeedTest extends TestCase
{
    public function testRecordIsIdempotentOnTheNaturalKey(): void
    {
        $repo = $this->inMemoryRepo();
        $record = new RecordActivity($repo);
        $now = new \DateTimeImmutable();

        self::assertTrue($record->record('u1', ActivityEntry::TYPE_RUN_FINISHED, 's1', $now));
        self::assertFalse($record->record('u1', ActivityEntry::TYPE_RUN_FINISHED, 's1', $now), 'same key -> no duplicate');
        self::assertTrue($record->record('u1', ActivityEntry::TYPE_RUN_FINISHED, 's2', $now));
        self::assertCount(2, $repo->recentForActors(['u1'], 50));
    }

    public function testBackfillMaterialisesFinishedRunsAndIsIdempotent(): void
    {
        $userIds = $this->createStub(CommunityUserIdsQueryInterface::class);
        $userIds->method('allUserIds')->willReturn(['u1']);

        $history = $this->createStub(PlayerHistoryQueryInterface::class);
        $history->method('fetchForUser')->willReturn([
            ['session_id' => 'sess1', 'event_name' => 'LAN', 'finished_at' => '2026-06-01T10:00:00+00:00', 'game' => 'Zelda'],
            ['session_id' => 'sess1', 'event_name' => 'LAN', 'finished_at' => '2026-06-01T10:00:00+00:00', 'game' => 'Metroid'],
            ['session_id' => 'sess2', 'event_name' => 'LAN', 'finished_at' => null, 'game' => 'Celeste'],
        ]);

        $repo = $this->inMemoryRepo();
        $backfill = new BackfillActivity($userIds, $history, new RecordActivity($repo));

        self::assertSame(2, $backfill->run(), 'two finished runs; the unfinished one is skipped');
        self::assertSame(0, $backfill->run(), 'idempotent on a second pass');
        self::assertCount(2, $repo->recentForActors(['u1'], 50));
    }

    private function inMemoryRepo(): ActivityEntryRepositoryInterface
    {
        return new class implements ActivityEntryRepositoryInterface {
            /** @var list<ActivityEntry> */
            private array $stored = [];

            public function exists(string $actorId, string $type, string $subjectRef): bool
            {
                foreach ($this->stored as $entry) {
                    if ($entry->getActorId() === $actorId && $entry->getType() === $type && $entry->getSubjectRef() === $subjectRef) {
                        return true;
                    }
                }

                return false;
            }

            public function ownerOf(string $entryId): ?string
            {
                foreach ($this->stored as $entry) {
                    if ($entry->getId() === $entryId) {
                        return $entry->getActorId();
                    }
                }

                return null;
            }

            public function save(ActivityEntry $entry): void
            {
                $this->stored[] = $entry;
            }

            public function recentForActors(array $actorIds, int $limit, ?\DateTimeImmutable $before = null): array
            {
                return array_values(array_filter(
                    $this->stored,
                    static fn (ActivityEntry $e): bool => in_array($e->getActorId(), $actorIds, true)
                        && (null === $before || $e->getOccurredAt() < $before),
                ));
            }
        };
    }
}
