<?php

declare(strict_types=1);

namespace App\Registrations\Domain;

use App\Events\Domain\Event;

interface RegistrationRepositoryInterface
{
    public function findById(string $id): ?Registration;

    public function findByEventAndUser(string $eventId, string $userId): ?Registration;

    /**
     * @param array<string, mixed>                     $criteria e.g. ['eventId' => ..., 'status' => ...]
     * @param array<string, 'ASC'|'asc'|'DESC'|'desc'> $orderBy
     *
     * @return list<Registration>
     */
    public function findBy(array $criteria, array $orderBy = [], ?int $limit = null): array;

    public function save(Registration $registration): void;

    public function persist(Registration $registration): void;

    public function flush(): void;

    /**
     * Acquires an exclusive (pessimistic write) lock on the event row for the duration
     * of the current database transaction. Returns null if the event does not exist.
     * Must be called inside beginTransaction() / commit() / rollBack().
     */
    public function findEventWithExclusiveLock(string $eventId): ?Event;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;
}
