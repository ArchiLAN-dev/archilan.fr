<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Hydrates (and validates) an achievement rule tree from its stored/submitted array form (story 30.16).
 * The root is always a group; nesting is capped to keep evaluation and the admin builder bounded.
 */
final class AchievementRuleFactory
{
    public const MAX_DEPTH = 5;

    /**
     * @param array<mixed, mixed> $data
     *
     * @throws InvalidAchievementRuleException
     */
    public static function fromArray(array $data): AchievementRule
    {
        $root = self::node($data, 0);
        if (!$root instanceof AchievementRuleGroup) {
            throw new InvalidAchievementRuleException('The root rule must be a group.');
        }

        return $root;
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @throws InvalidAchievementRuleException
     */
    private static function node(array $data, int $depth): AchievementRule
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidAchievementRuleException('Rule nesting is too deep.');
        }

        if (\array_key_exists('op', $data)) {
            return self::group($data, $depth);
        }
        if (\array_key_exists('fact', $data)) {
            return self::condition($data);
        }

        throw new InvalidAchievementRuleException('A rule node must be a group (op) or a condition (fact).');
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private static function group(array $data, int $depth): AchievementRuleGroup
    {
        $op = $data['op'] ?? null;
        if (!is_string($op) || !AchievementRuleGroup::isValidOp($op)) {
            throw new InvalidAchievementRuleException('Invalid group operator.');
        }

        $rawRules = $data['rules'] ?? null;
        if (!is_array($rawRules) || [] === $rawRules) {
            throw new InvalidAchievementRuleException('A group needs at least one child rule.');
        }

        $rules = [];
        foreach ($rawRules as $rawRule) {
            if (!is_array($rawRule)) {
                throw new InvalidAchievementRuleException('Each child rule must be an object.');
            }
            $rules[] = self::node($rawRule, $depth + 1);
        }

        return new AchievementRuleGroup($op, $rules);
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private static function condition(array $data): AchievementRuleCondition
    {
        $fact = $data['fact'] ?? null;
        if (!is_string($fact) || !AchievementMetricCatalog::isValidFact($fact)) {
            throw new InvalidAchievementRuleException('Unknown condition fact.');
        }

        $operatorRaw = $data['operator'] ?? null;
        $operator = is_string($operatorRaw) ? AchievementOperator::tryFromValue($operatorRaw) : null;
        if (null === $operator) {
            throw new InvalidAchievementRuleException('Invalid condition operator.');
        }

        $value = self::toInt($data['value'] ?? null);
        if (null === $value) {
            throw new InvalidAchievementRuleException('A condition value must be an integer.');
        }

        $value2 = null;
        if ($operator->requiresUpperBound()) {
            $value2 = self::toInt($data['value2'] ?? null);
            if (null === $value2) {
                throw new InvalidAchievementRuleException('A "between" condition needs an integer upper bound.');
            }
            if ($value2 < $value) {
                throw new InvalidAchievementRuleException('The upper bound must be >= the lower bound.');
            }
        }

        return new AchievementRuleCondition($fact, $operator, $value, $value2);
    }

    private static function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && '' !== $value && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }
}
