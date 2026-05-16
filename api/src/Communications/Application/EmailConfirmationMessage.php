<?php

declare(strict_types=1);

namespace App\Communications\Application;

final readonly class EmailConfirmationMessage
{
    public function __construct(
        public string $userEmail,
        public ?string $userDisplayName,
        public string $rawToken,
        public string $expiresAt,
    ) {
    }
}
