<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Write side of the notification center (story 30.12): the few Community write services emit notifications
 * through this interface so they stay decoupled from persistence + realtime push.
 */
interface Notifier
{
    /**
     * Create an in-app notification for the recipient (no-op if the recipient is empty or is the actor).
     *
     * @param array<string, mixed> $payload
     */
    public function notify(string $recipientId, string $type, array $payload): void;
}
