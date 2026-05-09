<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

final readonly class FetchLogsJob
{
    public function __construct(
        public string $sessionId,
    ) {
    }
}
