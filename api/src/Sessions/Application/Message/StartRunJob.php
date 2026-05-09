<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

final readonly class StartRunJob
{
    public function __construct(
        public string $sessionId,
        public ?int $existingPort = null,
        public ?int $existingBridgePort = null,
        public ?string $existingPassword = null,
        public ?string $existingServerPassword = null,
    ) {
    }
}
