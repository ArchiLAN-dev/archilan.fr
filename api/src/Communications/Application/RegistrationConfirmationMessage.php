<?php

declare(strict_types=1);

namespace App\Communications\Application;

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
    ) {
    }
}
