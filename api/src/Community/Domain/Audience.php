<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Who may see a profile's customization / social surface. The core profile (identity + aggregate stats)
 * is always public; this gates the rest (epic 30 §G).
 */
final class Audience
{
    public const PUBLIC = 'public';
    public const MEMBERS = 'members';
    public const FRIENDS = 'friends';

    public const ALL = [self::PUBLIC, self::MEMBERS, self::FRIENDS];

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
