<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

/**
 * The acknowledged outcome of {@see ReserveRegistration::reserve()}: which success path was taken and the id
 * of the resulting registration. A typed record instead of an `array{outcome, registrationId}` shape (epic 35
 * Stage 2). Colocated with the command that returns it (Application/Command/).
 */
final readonly class ReservationResult
{
    public function __construct(
        public ReservationOutcome $outcome,
        public string $registrationId,
    ) {
    }
}
