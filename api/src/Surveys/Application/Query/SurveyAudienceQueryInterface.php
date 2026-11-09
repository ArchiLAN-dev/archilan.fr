<?php

declare(strict_types=1);

namespace App\Surveys\Application\Query;

use App\Surveys\Domain\Enum\SurveyAudience;

/**
 * Who a survey speaks to (story 51.2), read in the registrations and the sessions:
 * - `registration` and `registrants`: an active registration on the event;
 * - `participants`: an active, confirmed registration with a slot in a session of the event that was launched; when
 *   no session of the event was ever launched, every active, confirmed registration.
 *
 * @phpstan-type AudienceMember array{userId: string, registrationId: string, displayName: string}
 */
interface SurveyAudienceQueryInterface
{
    public function includes(SurveyAudience $audience, string $eventId, string $userId): bool;

    /**
     * Story 51.4: the whole audience, one row per member, by display name.
     *
     * @return list<AudienceMember>
     */
    public function members(SurveyAudience $audience, string $eventId): array;
}
