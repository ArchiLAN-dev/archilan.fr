<?php

declare(strict_types=1);

namespace App\Events\Domain;

interface EventPrivateAccessLogRepositoryInterface
{
    public function save(EventPrivateAccessLog $log): void;

    /**
     * Delete access-log rows older than the given threshold (by creation time).
     *
     * @return int number of deleted rows
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold): int;
}
