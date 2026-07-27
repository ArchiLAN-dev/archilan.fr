<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\SessionFeedEvent;

interface SessionFeedEventRepositoryInterface
{
    public function save(SessionFeedEvent $event): void;

    /**
     * All events for a session, oldest first (by occurred_at).
     *
     * @return list<SessionFeedEvent>
     */
    public function findBySessionId(string $sessionId): array;
}
