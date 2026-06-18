<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * A boolean group combining child rules (story 30.16): ALL (AND), ANY (OR) or NONE (NOR). Nestable.
 */
final readonly class AchievementRuleGroup implements AchievementRule
{
    public const OP_ALL = 'all';
    public const OP_ANY = 'any';
    public const OP_NONE = 'none';

    public const OPS = [self::OP_ALL, self::OP_ANY, self::OP_NONE];

    /**
     * @param list<AchievementRule> $rules
     */
    public function __construct(
        public string $op,
        public array $rules,
    ) {
    }

    public static function isValidOp(string $op): bool
    {
        return \in_array($op, self::OPS, true);
    }

    public function matches(MetricBag $bag): bool
    {
        return match ($this->op) {
            self::OP_ALL => $this->allMatch($bag),
            self::OP_ANY => $this->anyMatch($bag),
            self::OP_NONE => !$this->anyMatch($bag),
            default => false,
        };
    }

    public function toArray(): array
    {
        return [
            'op' => $this->op,
            'rules' => array_map(static fn (AchievementRule $r): array => $r->toArray(), $this->rules),
        ];
    }

    private function allMatch(MetricBag $bag): bool
    {
        foreach ($this->rules as $rule) {
            if (!$rule->matches($bag)) {
                return false;
            }
        }

        return true;
    }

    private function anyMatch(MetricBag $bag): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($bag)) {
                return true;
            }
        }

        return false;
    }
}
