<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Repository;

use App\Surveys\Domain\Entity\Survey;
use App\Surveys\Domain\Entity\SurveyOption;
use App\Surveys\Domain\Entity\SurveyQuestion;

interface SurveyRepositoryInterface
{
    public function find(string $id): ?Survey;

    public function findQuestion(string $id): ?SurveyQuestion;

    public function findOption(string $id): ?SurveyOption;

    /** @return list<SurveyQuestion> in position order */
    public function questionsOf(string $surveyId): array;

    /**
     * @param list<string> $questionIds
     *
     * @return list<SurveyOption> in position order
     */
    public function optionsOf(array $questionIds): array;

    /** Everything given, in one flush. */
    public function save(Survey|SurveyQuestion|SurveyOption ...$entities): void;

    /**
     * Removes $removed and saves $kept, in one flush.
     *
     * @param list<Survey|SurveyQuestion|SurveyOption> $removed
     */
    public function delete(array $removed, Survey|SurveyQuestion|SurveyOption ...$kept): void;
}
