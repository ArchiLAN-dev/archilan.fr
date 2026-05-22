<?php

declare(strict_types=1);

namespace App\Sessions\Domain;

interface SessionSlotRepositoryInterface
{
    /**
     * @return list<SessionSlot>
     */
    public function findBySessionId(string $sessionId): array;

    /**
     * @return list<SessionSlot>
     */
    public function findByRegistrationAndSession(string $registrationId, string $sessionId): array;

    public function findBySessionAndSlotName(string $sessionId, string $slotName): ?SessionSlot;

    public function persist(SessionSlot $slot): void;

    public function flush(): void;
}
