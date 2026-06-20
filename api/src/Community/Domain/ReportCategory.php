<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * What part of a profile a report is about (story 30.28). The "Type de signalement" the reporter picks.
 * `comment` is set automatically for comment-target reports; the others are profile fields.
 */
final class ReportCategory
{
    public const AVATAR = 'avatar';
    public const DISPLAY_NAME = 'display_name';
    public const BIO = 'bio';
    public const SOCIAL_LINK = 'social_link';
    public const COMMENT = 'comment';
    public const OTHER = 'other';

    public const ALL = [
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
