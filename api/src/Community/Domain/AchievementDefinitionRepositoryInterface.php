<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface AchievementDefinitionRepositoryInterface
{
    /**
     * Active definitions, ordered by position (the recompute + public profile source).
     *
     * @return list<AchievementDefinition>
     */
    public function allActive(): array;

    /**
     * Every definition, ordered by position (admin list).
     *
     * @return list<AchievementDefinition>
     */
    public function all(): array;

    public function findById(string $id): ?AchievementDefinition;

    public function existsByKey(string $key): bool;

    /** Highest position currently stored, or -1 when the table is empty. */
    public function maxPosition(): int;

    public function save(AchievementDefinition $definition): void;

    public function flush(): void;
}
