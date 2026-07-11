<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

/**
 * The kind of problematic content a report flags (story 30.28). The "Contenu problématique" the reporter
 * picks; drives severity weighting (see {@see ReportSeverity}) so the worst cases surface first.
 */
final readonly class ReportProblem
{
    public const string NUDITY = 'nudity';
    public const string VIOLENCE = 'violence';
    public const string HATE = 'hate';
    public const string HARASSMENT = 'harassment';
    public const string SPAM = 'spam';
    public const string OTHER = 'other';

    public const array ALL = [
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
