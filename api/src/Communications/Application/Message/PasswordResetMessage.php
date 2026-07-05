<?php

declare(strict_types=1);

namespace App\Communications\Application\Message;

final readonly class PasswordResetMessage
{
    public function __construct(
        public string $userEmail,
        public ?string $userDisplayName,
        public string $rawToken,
        public string $expiresAt,
    ) {
    }
}
