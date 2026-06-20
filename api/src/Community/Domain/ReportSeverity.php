<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Maps a report's problem to a severity weight (story 30.28). Weighted sums per account drive both the
 * "most problematic first" ordering and the escalation threshold — so nudity/violence/hate pull an account
 * into review far faster than spam, and "other" barely counts. Pure domain logic, no config.
 */
final class ReportSeverity
{
    /** Problem => weight. The weakest (`other`) is 0 so a pure "Autre" report never escalates on its own. */
    public const WEIGHTS = [
        ReportProblem::NUDITY => 10,
        ReportProblem::VIOLENCE => 10,
        ReportProblem::HATE => 8,
        ReportProblem::HARASSMENT => 5,
        ReportProblem::SPAM => 2,
        ReportProblem::OTHER => 0,
    ];

    public static function weight(string $problem): int
    {
        return self::WEIGHTS[$problem] ?? 0;
    }

    /**
     * @param list<string> $problems
     */
    public static function sum(array $problems): int
    {
        $total = 0;
        foreach ($problems as $problem) {
            $total += self::weight($problem);
        }

        return $total;
    }

    /**
     * The "Autre / Autre / sans commentaire" bucket: no category, no problem, no free-text. These flood the
     * queue without signal, so they are parked in a low-priority bucket and never escalate/notify.
     */
    public static function isUncategorized(string $category, string $problem, ?string $comment): bool
    {
        return ReportCategory::OTHER === $category
            && ReportProblem::OTHER === $problem
            && (null === $comment || '' === trim($comment));
    }
}
