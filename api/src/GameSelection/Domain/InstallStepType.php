<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

/**
 * The kinds of step a per-game install tutorial can contain (story 31.1).
 */
enum InstallStepType: string
{
    case Acquire = 'acquire';
    case Apworld = 'apworld';
    case Client = 'client';
    case Yaml = 'yaml';
    case Connect = 'connect';
    case Note = 'note';

    public static function isValid(string $type): bool
    {
        return null !== self::tryFrom($type);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
