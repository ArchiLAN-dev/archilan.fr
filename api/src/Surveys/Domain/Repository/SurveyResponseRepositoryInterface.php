<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Repository;

use App\Surveys\Domain\Entity\SurveyAnswer;
use App\Surveys\Domain\Entity\SurveyParticipation;
use App\Surveys\Domain\Entity\SurveyResponse;

interface SurveyResponseRepositoryInterface
{
    public function findParticipation(string $surveyId, string $userId): ?SurveyParticipation;

    /** The member's response to a nominative survey; an anonymous response is never found by its author. */
    public function findNominativeResponse(string $surveyId, string $userId): ?SurveyResponse;

    /** @return list<SurveyAnswer> */
    public function answersOf(string $responseId): array;

    /**
     * The participation, the response and its answers, in one flush; $replaced are the answers they take the place
     * of.
     *
     * @param list<SurveyAnswer> $answers
     * @param list<SurveyAnswer> $replaced
     */
    public function record(SurveyParticipation $participation, SurveyResponse $response, array $answers, array $replaced): void;
}
