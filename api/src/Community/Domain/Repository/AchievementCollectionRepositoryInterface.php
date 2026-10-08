<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\AchievementCollection;
use App\Community\Domain\Entity\AchievementCollectionCompletion;

/** Story 30.52: the collections of achievements, and who completed which. */
interface AchievementCollectionRepositoryInterface
{
    /**
     * Every collection, ordered by position.
     *
     * @return list<AchievementCollection>
     */
    public function all(): array;

    public function findById(string $id): ?AchievementCollection;

    /** Highest position currently stored, or -1 when there is none. */
    public function maxPosition(): int;

    public function save(AchievementCollection $collection): void;

    public function remove(AchievementCollection $collection): void;

    public function flush(): void;

    /**
     * @return list<string> the ids of the collections the member completed
     */
    public function completedIds(string $userId): array;

    public function saveCompletion(AchievementCollectionCompletion $completion): void;
}
