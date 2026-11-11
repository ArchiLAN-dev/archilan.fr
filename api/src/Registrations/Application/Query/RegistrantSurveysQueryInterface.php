<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

/**
 * Story 53.2: the surveys a registrant's checklist mentions, read in the surveys (implemented by the Surveys context).
 */
interface RegistrantSurveysQueryInterface
{
    /** An open, published survey of audience `registration` exists on the event. */
    public function hasOpenRegistrationSurvey(string $eventId, \DateTimeImmutable $now): bool;

    /** Open, published surveys of audience `registrants` the member has not answered yet. */
    public function countPendingForRegistrant(string $eventId, string $userId, \DateTimeImmutable $now): int;
}
