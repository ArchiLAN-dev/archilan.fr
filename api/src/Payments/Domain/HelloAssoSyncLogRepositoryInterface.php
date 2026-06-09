<?php

declare(strict_types=1);

namespace App\Payments\Domain;

interface HelloAssoSyncLogRepositoryInterface
{
    /**
     * @return list<HelloAssoSyncLog>
     */
    public function findRecentByFormSlug(string $formSlug, int $limit = 10): array;

    public function persist(HelloAssoSyncLog $log): void;

    public function save(HelloAssoSyncLog $log): void;

    public function flush(): void;

    /**
     * Delete log rows older than the given threshold (by attempt time).
     *
     * @return int number of deleted rows
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold): int;
}
