<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\DepartureRequest;

/** Story 52.6: the departures of a session, open or closed. */
interface DepartureRequestRepositoryInterface
{
    /**
     * Writes the request unless the slot already has an open one: null then.
     */
    public function open(DepartureRequest $request): ?DepartureRequest;

    public function findById(string $id): ?DepartureRequest;

    public function findOpen(string $sessionId, string $slotName): ?DepartureRequest;

    /**
     * @return list<DepartureRequest> oldest first
     */
    public function findOpenBySession(string $sessionId): array;

    /** Whether the slot had a request (any status) created at or after `$since`. */
    public function existsSince(string $sessionId, string $slotName, \DateTimeImmutable $since): bool;

    public function flush(): void;
}
