<?php

declare(strict_types=1);

namespace App\Surveys\Application\Command;

/** Story 51.3: the outcome of a manual reminder, how many members it reached, and when the last reminder went out. */
final readonly class SurveyReminderResult
{
    public function __construct(
        public SurveyReminderOutcome $outcome,
        public int $recipients,
        public ?\DateTimeImmutable $remindedAt,
    ) {
    }
}
