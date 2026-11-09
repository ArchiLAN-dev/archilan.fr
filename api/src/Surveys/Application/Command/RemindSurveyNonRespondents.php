<?php

declare(strict_types=1);

namespace App\Surveys\Application\Command;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Surveys\Application\Service\SurveyAnnouncer;
use App\Surveys\Domain\Repository\SurveyRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * Story 51.3: an admin reminds the members who have not answered an open survey. Two reminders, the automatic one
 * included, are at least 24 hours apart.
 */
final readonly class RemindSurveyNonRespondents
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyAnnouncer $announcer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws NotFoundException when the survey does not exist
     * @throws ConflictException when the survey is not open
     */
    public function __invoke(string $surveyId): SurveyReminderResult
    {
        $survey = $this->surveys->find($surveyId) ?? throw new NotFoundException('Sondage introuvable.', 'survey_not_found');
        $now = $this->clock->now();

        try {
            $survey->remindByHand($now);
        } catch (\DomainException $e) {
            if ('survey_remind_too_soon' === $e->getMessage()) {
                return new SurveyReminderResult(SurveyReminderOutcome::TooSoon, 0, $survey->getLastRemindedAt());
            }

            throw new ConflictException('Ce sondage n\'est pas ouvert.', 'survey_closed');
        }
        $this->surveys->save($survey);

        return new SurveyReminderResult(SurveyReminderOutcome::Sent, $this->announcer->announce($survey, true), $now);
    }
}
