<?php

declare(strict_types=1);

namespace App\Community\Application\Message;

/**
 * Dispatched once a pushable notification is saved (story 40.2), to push it to its recipient's devices.
 */
final readonly class SendWebPushJob
{
    public function __construct(
        public string $notificationId,
    ) {
    }
}
