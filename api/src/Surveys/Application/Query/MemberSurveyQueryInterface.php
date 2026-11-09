<?php

declare(strict_types=1);

namespace App\Surveys\Application\Query;

/**
 * Reads the surveys as a member answers them (story 51.2).
 *
 * @phpstan-type SurveyHeader array{id: string, eventId: string, title: string, intro: string, audience: string, anonymous: bool, published: bool, opensAt: \DateTimeImmutable, closesAt: \DateTimeImmutable}
 * @phpstan-type FormOption array{id: string, label: string}
 * @phpstan-type FormQuestion array{id: string, kind: string, label: string, required: bool, options: list<FormOption>}
 * @phpstan-type MyAnswer array{questionId: string, optionIds: list<string>, text: string|null, rating: int|null}
 */
interface MemberSurveyQueryInterface
{
    /**
     * The published surveys of an event open at $now, closing soonest first.
     *
     * @return list<SurveyHeader>
     */
    public function openForEvent(string $eventId, \DateTimeImmutable $now): array;

    /** @return SurveyHeader|null */
    public function header(string $surveyId): ?array;

    /**
     * The questions in order, each with the options still offered.
     *
     * @return list<FormQuestion>
     */
    public function questions(string $surveyId): array;

    /**
     * Among the given surveys, those the member answered.
     *
     * @param list<string> $surveyIds
     *
     * @return list<string>
     */
    public function answered(array $surveyIds, string $userId): array;

    /**
     * The member's answers to a nominative survey, by question; empty when there are none.
     *
     * @return list<MyAnswer>
     */
    public function myAnswers(string $surveyId, string $userId): array;
}
