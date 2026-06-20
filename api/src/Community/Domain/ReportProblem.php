<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * The kind of problematic content a report flags (story 30.28). The "Contenu problématique" the reporter
 * picks; drives severity weighting (see {@see ReportSeverity}) so the worst cases surface first.
 */
final class ReportProblem
{
    public const NUDITY = 'nudity';
    public const VIOLENCE = 'violence';
    public const HATE = 'hate';
    public const HARASSMENT = 'harassment';
    public const SPAM = 'spam';
    public const OTHER = 'other';

    public const ALL = [
        self::NUDITY,
        self::VIOLENCE,
        self::HATE,
        self::HARASSMENT,
        self::SPAM,
        self::OTHER,
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
