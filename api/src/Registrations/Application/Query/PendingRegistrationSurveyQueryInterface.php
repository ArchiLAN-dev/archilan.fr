<?php

declare(strict_types=1);

namespace App\Registrations\Application\Query;

/**
 * Story 51.5: whether the organisers' questions at registration still wait for the member. Read in the surveys
 * (implemented by the Surveys context): an open, published survey of audience `registration` with at least one
 * required question, that the member has not answered.
 */
interface PendingRegistrationSurveyQueryInterface
{
    public function hasUnansweredRequired(string $eventId, string $userId, \DateTimeImmutable $now): bool;
}
