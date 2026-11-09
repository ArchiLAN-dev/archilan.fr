<?php

declare(strict_types=1);

namespace App\Surveys\Application\Query;

/**
 * Reads what the members answered, for the admin screens (story 51.4). Dates are ISO 8601.
 *
 * @phpstan-import-type MyAnswer from MemberSurveyQueryInterface
 *
 * @phpstan-type Tally array{respondents: int, perQuestion: array<string, int>, perOption: array<string, int>, ratings: array<string, array<int, int>>}
 * @phpstan-type Respondent array{userId: string, displayName: string, registrationId: string|null, respondedAt: string}
 * @phpstan-type LabelledAnswer array{question: string, kind: string, choices: list<string>, text: string|null, rating: int|null}
 * @phpstan-type AnsweredSurvey array{surveyId: string, title: string, respondedAt: string, answers: list<LabelledAnswer>}
 */
interface SurveyResultsQueryInterface
{
    /**
     * How many members answered, and how many responses touch each question, each option and each mark of a rating
     * question (keyed by question id, then by mark).
     *
     * @return Tally
     */
    public function tally(string $surveyId): array;

    /**
     * The text answers by question id, in alphabetical order: never in the order they came, which would match them
     * with the participations of an anonymous survey.
     *
     * @return array<string, list<string>>
     */
    public function texts(string $surveyId): array;

    /**
     * Who answered, by display name, with their registration on the event when they have one (the active one first).
     *
     * @return list<Respondent>
     */
    public function respondents(string $surveyId, string $eventId): array;

    /**
     * The nominative answers of a survey, keyed by member.
     *
     * @return array<string, list<MyAnswer>>
     */
    public function nominativeAnswers(string $surveyId): array;

    /** The member a registration of the event belongs to, or null when the event has no such registration. */
    public function registrant(string $eventId, string $registrationId): ?string;

    /**
     * The published nominative surveys of the event the member answered, newest first, with question and option
     * labels.
     *
     * @return list<AnsweredSurvey>
     */
    public function answeredBy(string $eventId, string $userId): array;
}
