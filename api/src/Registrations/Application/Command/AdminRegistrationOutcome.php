<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

/** Story 5.10: what an admin registration did. */
enum AdminRegistrationOutcome: string
{
    case Created = 'created';
    case Restored = 'restored';
    case AlreadyRegistered = 'already_registered';
}
