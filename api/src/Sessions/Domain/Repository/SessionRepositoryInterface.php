<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\Session;

interface SessionRepositoryInterface
{
    public function findById(string $id): ?Session;

    /**
     * @param list<string> $ids
     *
     * @return list<Session>
     */
    public function findByIds(array $ids): array;

    /**
     * @return list<Session>
     */
    public function findByEventId(string $eventId): array;

    /**
     * @return list<Session>
     */
    public function findByStatus(string $status): array;

    /**
     * @param list<string> $statuses
     *
     * @return list<Session>
     */
    public function findByStatuses(array $statuses): array;

    public function findMostRecentFinishedByEventId(string $eventId): ?Session;

    public function persist(Session $session): void;

    public function flush(): void;
}
