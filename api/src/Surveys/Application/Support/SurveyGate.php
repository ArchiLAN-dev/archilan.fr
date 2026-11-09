<?php

declare(strict_types=1);

namespace App\Surveys\Application\Support;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Surveys\Application\Query\SurveyAudienceQueryInterface;
use App\Surveys\Domain\Enum\SurveyAudience;

/**
 * Whether a member may read and answer a survey (story 51.2). A draft does not exist for a member; a survey for
 * others is refused; a survey outside its period is closed.
 */
final readonly class SurveyGate
{
    public function __construct(private SurveyAudienceQueryInterface $audience)
    {
    }

    /**
     * @throws NotFoundException  when the survey is a draft
     * @throws ForbiddenException when the member is not in its audience
     * @throws ConflictException  when the survey is not open
     */
    public function assertAnswerable(
        bool $published,
        \DateTimeImmutable $opensAt,
        \DateTimeImmutable $closesAt,
        SurveyAudience $audience,
        string $eventId,
        string $userId,
        \DateTimeImmutable $now,
    ): void {
        if (!$published) {
            throw new NotFoundException('Sondage introuvable.', 'survey_not_found');
        }
        if (!$this->audience->includes($audience, $eventId, $userId)) {
            throw new ForbiddenException('Ce sondage ne s\'adresse pas à toi.', 'survey_not_eligible');
        }
        if ($now < $opensAt || $now >= $closesAt) {
            throw new ConflictException('Ce sondage n\'est pas ouvert.', 'survey_closed');
        }
    }
}
