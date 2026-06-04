<?php

declare(strict_types=1);

namespace App\Events\Domain;

interface EventRepositoryInterface
{
    public function findById(string $id): ?Event;

    /**
     * @param list<string> $statuses
     *
     * @return list<Event>
     */
    public function findByStatuses(array $statuses, int $limit = 500): array;

    /**
     * @return list<Event>
     */
    public function findAllSortedByStartsAt(int $limit = 500): array;

    /**
     * @return list<Event>
     */
    public function findByStatus(string $status, int $limit = 500): array;

    public function save(Event $event): void;
}
