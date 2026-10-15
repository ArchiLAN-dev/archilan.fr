<?php

declare(strict_types=1);

namespace App\Communications\Application\Message;

/** Story 4.23: a reserved, unconfirmed registration two days before registrations close. */
final readonly class RegistrationConfirmationReminderMessage
{
    public function __construct(
        public string $userEmail,
        public ?string $userDisplayName,
        public string $eventTitle,
        public string $registrationClosesAt,
        public string $recapUrl,
    ) {
    }
}
