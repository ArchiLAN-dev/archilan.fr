<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

final readonly class StopRunJob
{
    public function __construct(
        public string $sessionId,
        public int $port,
        public int $bridgePort,
    ) {
    }
}
