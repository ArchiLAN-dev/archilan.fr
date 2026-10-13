<?php

declare(strict_types=1);

namespace App\Communications\Application\Message;

/** Story 4.23: an unconfirmed registration cancelled when registrations closed. */
final readonly class RegistrationReleasedMessage
{
    public function __construct(
        public string $userEmail,
        public ?string $userDisplayName,
        public string $eventTitle,
        public string $eventUrl,
    ) {
    }
}
