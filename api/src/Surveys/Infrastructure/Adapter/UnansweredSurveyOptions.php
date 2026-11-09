<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Adapter;

use App\Surveys\Application\Port\SurveyAnswerCountInterface;

/**
 * No answer is stored before story 51.2, so no option has any yet. Story 51.2 replaces this with a read of the
 * stored answers.
 */
final readonly class UnansweredSurveyOptions implements SurveyAnswerCountInterface
{
    public function optionHasAnswers(string $optionId): bool
    {
        return false;
    }
}
