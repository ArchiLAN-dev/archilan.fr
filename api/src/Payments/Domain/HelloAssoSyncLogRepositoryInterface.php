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
}
