<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * A leaf condition: a fact compared to a threshold (story 30.16). `value2` is the upper bound for `between`.
 */
final readonly class AchievementRuleCondition implements AchievementRule
{
    public function __construct(
        public string $fact,
        public AchievementOperator $operator,
        public int $value,
        public ?int $value2 = null,
    ) {
    }

    public function matches(MetricBag $bag): bool
    {
        return $this->operator->evaluate($bag->get($this->fact), $this->value, $this->value2);
    }

    public function toArray(): array
    {
        $out = ['fact' => $this->fact, 'operator' => $this->operator->value, 'value' => $this->value];
        if ($this->operator->requiresUpperBound()) {
            $out['value2'] = $this->value2 ?? $this->value;
        }

        return $out;
    }
}
