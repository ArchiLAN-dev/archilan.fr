<?php

declare(strict_types=1);

namespace App\Surveys\Application\Query;

/**
 * Reads the surveys of an event as the admin screens show them (story 51.1). Dates are ISO 8601.
 *
 * @phpstan-type SurveySummary array{id: string, title: string, audience: string, anonymous: bool, status: string, opensAt: string, closesAt: string, questionCount: int}
 * @phpstan-type OptionView array{id: string, label: string, position: int, retired: bool}
 * @phpstan-type QuestionView array{id: string, kind: string, label: string, required: bool, position: int, options: list<OptionView>}
 * @phpstan-type SurveyView array{id: string, eventId: string, title: string, intro: string, audience: string, anonymous: bool, sendEmail: bool, status: string, opensAt: string, closesAt: string, publishedAt: string|null, openingNotifiedAt: string|null, reminderSentAt: string|null, lastRemindedAt: string|null, createdAt: string, updatedAt: string, questions: list<QuestionView>}
 */
interface AdminSurveyQueryInterface
{
    /**
     * Newest first.
     *
     * @return list<SurveySummary>
     */
    public function forEvent(string $eventId): array;

    /**
     * Questions and options in position order, retired options included. Null when the survey does not exist.
     *
     * @return SurveyView|null
     */
    public function survey(string $surveyId): ?array;
}
