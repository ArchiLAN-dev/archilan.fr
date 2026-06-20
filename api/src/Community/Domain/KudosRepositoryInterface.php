<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface KudosRepositoryInterface
{
    public function find(string $actorId, string $targetType, string $targetId): ?Kudos;

    public function count(string $targetType, string $targetId): int;

    /**
     * @param list<string> $targetIds
     *
     * @return array<string, int> kudos count keyed by targetId (only non-zero entries)
     */
    public function countsFor(string $targetType, array $targetIds): array;

    /**
     * @param list<string> $targetIds
     *
     * @return list<string> the targetIds (of this type) the actor has given kudos to
     */
    public function givenBy(string $actorId, string $targetType, array $targetIds): array;

    public function save(Kudos $kudos): void;

    public function remove(Kudos $kudos): void;
}
