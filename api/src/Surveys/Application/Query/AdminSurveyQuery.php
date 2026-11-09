<?php

declare(strict_types=1);

namespace App\Surveys\Application\Query;

use App\Shared\Application\Exception\NotFoundException;
use App\Surveys\Application\Port\SurveyEventInterface;

/**
 * The surveys of an event for the admin screens (story 51.1).
 *
 * @phpstan-import-type SurveySummary from AdminSurveyQueryInterface
 * @phpstan-import-type SurveyView from AdminSurveyQueryInterface
 */
final readonly class AdminSurveyQuery
{
    public function __construct(
        private AdminSurveyQueryInterface $query,
        private SurveyEventInterface $events,
    ) {
    }

    /**
     * @return list<SurveySummary>
     *
     * @throws NotFoundException when the event does not exist
     */
    public function forEvent(string $eventId): array
    {
        if (null === $this->events->period($eventId)) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }

        return $this->query->forEvent($eventId);
    }

    /**
     * @return SurveyView
     *
     * @throws NotFoundException when the survey does not exist
     */
    public function survey(string $surveyId): array
    {
        return $this->query->survey($surveyId) ?? throw new NotFoundException('Sondage introuvable.', 'survey_not_found');
    }
}
