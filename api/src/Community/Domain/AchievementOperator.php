<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Comparison operators for a configurable achievement condition (story 30.16). Pure; integer-valued.
 */
enum AchievementOperator: string
{
    case GreaterOrEqual = '>=';
    case GreaterThan = '>';
    case Equal = '=';
    case NotEqual = '!=';
    case LessOrEqual = '<=';
    case LessThan = '<';
    case Between = 'between';

    public static function tryFromValue(string $value): ?self
    {
        return self::tryFrom($value);
    }

    public function requiresUpperBound(): bool
    {
        return self::Between === $this;
    }

    public function evaluate(int $left, int $value, ?int $value2): bool
    {
        return match ($this) {
            self::GreaterOrEqual => $left >= $value,
            self::GreaterThan => $left > $value,
            self::Equal => $left === $value,
            self::NotEqual => $left !== $value,
            self::LessOrEqual => $left <= $value,
            self::LessThan => $left < $value,
            self::Between => $left >= $value && $left <= ($value2 ?? $value),
        };
    }
}
