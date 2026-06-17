<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain;

interface YamlTemplateRepositoryInterface
{
    public function findById(string $id): ?YamlTemplate;

    /**
     * @return list<YamlTemplate> the user's templates for a game, most recently updated first
     */
    public function findByUserAndGame(string $userId, string $gameId): array;

    public function existsByUserGameName(string $userId, string $gameId, string $name, ?string $excludeId = null): bool;

    public function save(YamlTemplate $template): void;

    public function delete(YamlTemplate $template): void;

    /** Remove every template owned by a user (account-erasure cascade). */
    public function deleteByUserId(string $userId): void;

    public function flush(): void;
}
