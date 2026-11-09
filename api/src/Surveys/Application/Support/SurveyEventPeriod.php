<?php

declare(strict_types=1);

namespace App\Surveys\Application\Support;

/** When an event starts and ends, which a survey's default period is drawn from (story 51.1). */
final readonly class SurveyEventPeriod
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public string $title = '',
    ) {
    }
}
