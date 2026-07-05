<?php

declare(strict_types=1);

namespace App\Communications\Application\Message;

final readonly class SessionRestartFailedMessage
{
    public function __construct(
        public string $sessionId,
    ) {
    }
}
