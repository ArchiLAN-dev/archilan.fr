<?php

declare(strict_types=1);

namespace App\Communications\Application\Message;

final readonly class RegistrationConfirmationMessage
{
    /**
     * @param list<string> $selectedGameNames
     */
    public function __construct(
        public string $userEmail,
        public ?string $userDisplayName,
        public string $eventTitle,
        public string $eventStartsAt,
        public string $eventVenue,
        public array $selectedGameNames,
        // Story 4.15: to link the registration from the mail. Empty for a message queued before the deploy.
        public string $eventId = '',
        public string $registrationId = '',
    ) {
    }
}
