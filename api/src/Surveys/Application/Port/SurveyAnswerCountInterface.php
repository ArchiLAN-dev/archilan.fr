<?php

declare(strict_types=1);

namespace App\Surveys\Application\Port;

/**
 * Whether an option already has answers (story 51.1): an answered option is never renamed. Answers arrive with
 * story 51.2, which answers this from the stored answers.
 */
interface SurveyAnswerCountInterface
{
    public function optionHasAnswers(string $optionId): bool;
}
