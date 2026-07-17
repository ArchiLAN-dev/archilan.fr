<?php

declare(strict_types=1);

namespace App\Registrations\Application\Command;

/**
 * The two success outcomes of a seat reservation: a fresh reservation (HTTP 201) or an idempotent hit on an
 * already-active registration (HTTP 200). String-backed so the value stays the wire discriminant (epic 35
 * Stage 2). Failure paths throw typed ApplicationFailures instead of appearing here.
 */
enum ReservationOutcome: string
{
    case Reserved = 'reserved';
    case AlreadyRegistered = 'already_registered';
}
