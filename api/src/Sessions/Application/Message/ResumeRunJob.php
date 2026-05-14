<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

final readonly class ResumeRunJob
{
    public function __construct(
        public string $sessionId,
        public string $lastSaveKey,
        public string $password,
        public string $serverPassword,
        public int $bridgePort = 0,
    ) {
    }
}
