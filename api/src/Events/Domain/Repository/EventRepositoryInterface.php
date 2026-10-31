<?php

declare(strict_types=1);

namespace App\Events\Domain\Repository;

use App\Events\Domain\Entity\Event;

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

    /**
     * Story 3.15: the events featured on the home page (one at most, a list so a stray second one is still found).
     *
     * @return list<Event>
     */
    public function findFeaturedOnHome(): array;

    public function save(Event $event): void;
}
