<?php

declare(strict_types=1);

namespace App\Surveys\Application\Query;

use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Surveys\Domain\Enum\QuestionKind;
use App\Surveys\Domain\Enum\SurveyAudience;

/**
 * Story 51.4: what the members answered, for the admins. The participation is always nominative, so the
 * non-respondents are named for every survey; the answers of each member are shown for a nominative survey only.
 * The text answers of an anonymous survey stay hidden below {@see self::TEXT_THRESHOLD} responses, where one could
 * be told apart.
 *
 * @phpstan-import-type SurveyView from AdminSurveyQueryInterface
 * @phpstan-import-type AudienceMember from SurveyAudienceQueryInterface
 * @phpstan-import-type Respondent from SurveyResultsQueryInterface
 * @phpstan-import-type AnsweredSurvey from SurveyResultsQueryInterface
 * @phpstan-import-type MyAnswer from MemberSurveyQueryInterface
 *
 * @phpstan-type OptionResult array{id: string, label: string, retired: bool, count: int}
 * @phpstan-type RatingResult array{average: float|null, counts: list<int>}
 * @phpstan-type QuestionResult array{id: string, kind: string, label: string, required: bool, answerCount: int, options: list<OptionResult>, rating: RatingResult|null, texts: list<string>|null}
 * @phpstan-type SurveyResults array{surveyId: string, eventId: string, title: string, anonymous: bool, status: string, closesAt: string, respondentCount: int, audienceSize: int, textsHidden: bool, questions: list<QuestionResult>}
 * @phpstan-type RespondentAnswers array{userId: string, displayName: string, registrationId: string|null, respondedAt: string, answers: list<MyAnswer>}
 */
final readonly class SurveyResultsQuery
{
    public const int TEXT_THRESHOLD = 3;

    private const array MARKS = [1, 2, 3, 4, 5];

    public function __construct(
        private AdminSurveyQueryInterface $surveys,
        private SurveyResultsQueryInterface $results,
        private SurveyAudienceQueryInterface $audience,
    ) {
    }

    /**
     * @return SurveyResults
     *
     * @throws NotFoundException when the survey does not exist
     */
    public function results(string $surveyId): array
    {
        $survey = $this->survey($surveyId);
        $tally = $this->results->tally($surveyId);
        $textsHidden = $survey['anonymous'] && $tally['respondents'] < self::TEXT_THRESHOLD;
        $texts = $textsHidden ? [] : $this->results->texts($surveyId);

        $questions = [];
        foreach ($survey['questions'] as $question) {
            $kind = QuestionKind::tryFrom($question['kind']);
            $rating = null;
            if (QuestionKind::Rating === $kind) {
                $marks = $tally['ratings'][$question['id']] ?? [];
                $counts = array_map(static fn (int $mark): int => $marks[$mark] ?? 0, self::MARKS);
                $total = array_sum($counts);
                $sum = array_sum(array_map(static fn (int $mark): int => $mark * ($marks[$mark] ?? 0), self::MARKS));
                $rating = ['average' => 0 === $total ? null : round($sum / $total, 2), 'counts' => $counts];
            }
            $questions[] = [
                'id' => $question['id'],
                'kind' => $question['kind'],
                'label' => $question['label'],
                'required' => $question['required'],
                'answerCount' => $tally['perQuestion'][$question['id']] ?? 0,
                'options' => array_map(static fn (array $option): array => [
                    'id' => $option['id'],
                    'label' => $option['label'],
                    'retired' => $option['retired'],
                    'count' => $tally['perOption'][$option['id']] ?? 0,
                ], $question['options']),
                'rating' => $rating,
                'texts' => QuestionKind::Text === $kind && !$textsHidden ? ($texts[$question['id']] ?? []) : null,
            ];
        }

        return [
            'surveyId' => $survey['id'],
            'eventId' => $survey['eventId'],
            'title' => $survey['title'],
            'anonymous' => $survey['anonymous'],
            'status' => $survey['status'],
            'closesAt' => $survey['closesAt'],
            'respondentCount' => $tally['respondents'],
            'audienceSize' => count($this->members($survey)),
            'textsHidden' => $textsHidden,
            'questions' => $questions,
        ];
    }

    /**
     * Each member's answers, for a nominative survey.
     *
     * @return list<RespondentAnswers>
     *
     * @throws NotFoundException  when the survey does not exist
     * @throws ForbiddenException when the survey is anonymous
     */
    public function respondents(string $surveyId): array
    {
        $survey = $this->survey($surveyId);
        if ($survey['anonymous']) {
            throw new ForbiddenException('Les réponses d\'un sondage anonyme ne se lisent pas par joueur.', 'survey_anonymous');
        }
        $answers = $this->results->nominativeAnswers($surveyId);

        return array_map(static fn (array $respondent): array => [
            ...$respondent,
            'answers' => $answers[$respondent['userId']] ?? [],
        ], $this->results->respondents($surveyId, $survey['eventId']));
    }

    /**
     * The members of the audience who have not answered, by display name.
     *
     * @return list<AudienceMember>
     *
     * @throws NotFoundException when the survey does not exist
     */
    public function nonRespondents(string $surveyId): array
    {
        $survey = $this->survey($surveyId);
        $answered = array_flip(array_map(
            static fn (array $respondent): string => $respondent['userId'],
            $this->results->respondents($surveyId, $survey['eventId']),
        ));

        return array_values(array_filter(
            $this->members($survey),
            static fn (array $member): bool => !isset($answered[$member['userId']]),
        ));
    }

    /**
     * The nominative answers of the member behind a registration, survey by survey.
     *
     * @return list<AnsweredSurvey>
     *
     * @throws NotFoundException when the event has no such registration
     */
    public function forRegistration(string $eventId, string $registrationId): array
    {
        $userId = $this->results->registrant($eventId, $registrationId)
            ?? throw new NotFoundException('Inscription introuvable.', 'registration_not_found');

        return $this->results->answeredBy($eventId, $userId);
    }

    /** @return SurveyView */
    private function survey(string $surveyId): array
    {
        return $this->surveys->survey($surveyId) ?? throw new NotFoundException('Sondage introuvable.', 'survey_not_found');
    }

    /**
     * @param SurveyView $survey
     *
     * @return list<AudienceMember>
     */
    private function members(array $survey): array
    {
        $audience = SurveyAudience::tryFrom($survey['audience']);

        return null === $audience ? [] : $this->audience->members($audience, $survey['eventId']);
    }
}
