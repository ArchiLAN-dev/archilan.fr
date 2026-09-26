<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Exception;

use App\GameSelection\Domain\Enum\ApworldIncidentStatus;

/**
 * A transition that the incident lifecycle forbids, such as resolving an incident that is already
 * closed (story 38.1).
 */
final class ApworldIncidentTransitionException extends \DomainException
{
    public static function notActive(string $incidentId, ApworldIncidentStatus $status, string $transition): self
    {
        return new self(sprintf('Incident %s is %s and cannot be %s.', $incidentId, $status->value, $transition));
    }
}
