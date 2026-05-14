<?php

declare(strict_types=1);

namespace App\Communications\Application;

final readonly class SessionRestartFailedMessage
{
    public function __construct(
        public string $sessionId,
    ) {
    }
}
