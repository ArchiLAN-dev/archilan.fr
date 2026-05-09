<?php

declare(strict_types=1);

namespace App\Communications\Application;

final readonly class SessionRunningMessage
{
    /**
     * @param list<string> $slotNames
     */
    public function __construct(
        public string $sessionId,
        public string $registrationId,
        public string $userId,
        public string $userEmail,
        public ?string $userDisplayName,
        public string $eventTitle,
        public string $host,
        public int $port,
        public string $password,
        public array $slotNames,
    ) {
    }
}
