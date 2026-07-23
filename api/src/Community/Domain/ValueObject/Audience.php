<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

/**
 * Who may see a profile's customization / social surface. The core profile (identity + aggregate stats)
 * is always public; this gates the rest (epic 30 §G).
 */
final readonly class Audience
{
    public const string PUBLIC = 'public';
    public const string MEMBERS = 'members';
    public const string FRIENDS = 'friends';

    public const array ALL = [self::PUBLIC, self::MEMBERS, self::FRIENDS];

    /**
     * Audience a profile is born with (story 30.28).
     *
     * Single source of truth: this value used to be spelled out at every default site - the entity, the
     * migration, the update handler, the read fallbacks and the frontend form - which is how they were
     * free to disagree. Point every default here instead.
     *
     * This governs NEW profiles only. Rows created before the change keep whatever they hold: nothing in
     * the data tells a deliberate `members` apart from one that was merely the old default, so widening
     * them would publish profiles their owner had chosen to restrict.
     */
    public const string DEFAULT = self::PUBLIC;

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
