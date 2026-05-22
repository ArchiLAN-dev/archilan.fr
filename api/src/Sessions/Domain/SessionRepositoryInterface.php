<?php

declare(strict_types=1);

namespace App\Sessions\Domain;

interface SessionRepositoryInterface
{
    public function findById(string $id): ?Session;

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
