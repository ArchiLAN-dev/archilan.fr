<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

/**
 * What part of a profile a report is about (story 30.28). The "Type de signalement" the reporter picks.
 * `comment` is set automatically for comment-target reports; the others are profile fields.
 */
final readonly class ReportCategory
{
    public const string AVATAR = 'avatar';
    public const string DISPLAY_NAME = 'display_name';
    public const string BIO = 'bio';
    public const string SOCIAL_LINK = 'social_link';
    public const string COMMENT = 'comment';
    public const string OTHER = 'other';

    public const array ALL = [
        self::AVATAR,
        self::DISPLAY_NAME,
        self::BIO,
        self::SOCIAL_LINK,
        self::COMMENT,
        self::OTHER,
    ];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
