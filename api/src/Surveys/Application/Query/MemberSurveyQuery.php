<?php

declare(strict_types=1);

namespace App\Surveys\Application\Query;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Surveys\Application\Support\SurveyGate;
use App\Surveys\Domain\Enum\SurveyAudience;
use Psr\Clock\ClockInterface;

/**
 * The surveys a member is asked, and one survey as the member answers it (story 51.2).
 *
 * @phpstan-import-type FormQuestion from MemberSurveyQueryInterface
 * @phpstan-import-type MyAnswer from MemberSurveyQueryInterface
 *
 * @phpstan-type MySurvey array{id: string, title: string, audience: string, anonymous: bool, closesAt: string, answered: bool}
 * @phpstan-type SurveyForm array{id: string, eventId: string, title: string, intro: string, anonymous: bool, closesAt: string, answered: bool, questions: list<FormQuestion>, myAnswers: list<MyAnswer>|null}
 */
final readonly class MemberSurveyQuery
{
    public function __construct(
        private MemberSurveyQueryInterface $query,
        private SurveyAudienceQueryInterface $audience,
        private SurveyGate $gate,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The open surveys of an event that speak to the member.
     *
     * @return list<MySurvey>
     */
    public function forEvent(string $eventId, string $userId): array
    {
        $mine = array_values(array_filter(
            $this->query->openForEvent($eventId, $this->clock->now()),
            fn (array $survey): bool => $this->audience->includes(self::audienceOf($survey['audience']), $eventId, $userId),
        ));
        $answered = $this->query->answered(array_column($mine, 'id'), $userId);

        return array_map(static fn (array $survey): array => [
            'id' => $survey['id'],
            'title' => $survey['title'],
            'audience' => $survey['audience'],
            'anonymous' => $survey['anonymous'],
            'closesAt' => $survey['closesAt']->format(\DateTimeInterface::ATOM),
            'answered' => in_array($survey['id'], $answered, true),
        ], $mine);
    }

    /**
     * The survey's questions and, for a nominative survey, the member's answers so far (null for an anonymous one).
     *
     * @return SurveyForm
     *
     * @throws NotFoundException  when the survey does not exist or is a draft
     * @throws ForbiddenException when the member is not in its audience
     * @throws ConflictException  when the survey is not open
     */
    public function form(string $surveyId, string $userId): array
    {
        $survey = $this->query->header($surveyId) ?? throw new NotFoundException('Sondage introuvable.', 'survey_not_found');
        $this->gate->assertAnswerable($survey['published'], $survey['opensAt'], $survey['closesAt'], self::audienceOf($survey['audience']), $survey['eventId'], $userId, $this->clock->now());

        return [
            'id' => $survey['id'],
            'eventId' => $survey['eventId'],
            'title' => $survey['title'],
            'intro' => $survey['intro'],
            'anonymous' => $survey['anonymous'],
            'closesAt' => $survey['closesAt']->format(\DateTimeInterface::ATOM),
            'answered' => [] !== $this->query->answered([$surveyId], $userId),
            'questions' => $this->query->questions($surveyId),
            'myAnswers' => $survey['anonymous'] ? null : $this->query->myAnswers($surveyId, $userId),
        ];
    }

    /** The audience is written by the survey's own enum: an unknown value would be a corrupt row, read as the narrowest audience. */
    private static function audienceOf(string $value): SurveyAudience
    {
        return SurveyAudience::tryFrom($value) ?? SurveyAudience::Participants;
    }
}
