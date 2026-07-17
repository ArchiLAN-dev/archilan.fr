<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

/**
 * The confirmed-registration acknowledgement returned by {@see RegistrationSubmission::submit()}: the
 * registration id, the event title, and the games the registrant selected (echoed to the confirmation screen).
 * A typed record instead of an `array{registrationId, eventTitle, selectedGameIds}` shape (epic 35 Stage 2).
 * Colocated with the command that returns it (Application/Command/).
 */
final readonly class SubmissionResult
{
    /**
     * @param list<string> $selectedGameIds
     */
    public function __construct(
        public string $registrationId,
        public string $eventTitle,
        public array $selectedGameIds,
    ) {
    }
}
