<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

use App\Registrations\Application\Support\AdminRegistrationWarning;

/** Story 5.10: the registration an admin created or put back, and the warnings they acknowledged to do it. */
final readonly class AdminRegistrationCreated
{
    /**
     * @param list<AdminRegistrationWarning> $acknowledgedWarnings
     */
    public function __construct(
        public string $registrationId,
        public AdminRegistrationOutcome $outcome,
        public array $acknowledgedWarnings,
    ) {
    }
}
