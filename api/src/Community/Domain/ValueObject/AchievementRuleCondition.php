<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

use App\Community\Domain\AchievementOperator;
use App\Community\Domain\Service\AchievementRule;

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

    /**
     * @return array{type: 'condition', fact: string, operator: string, value: int, value2: int|null, current: int, met: bool}
     */
    public function progress(MetricBag $bag): array
    {
        return [
            'type' => 'condition',
            'fact' => $this->fact,
            'operator' => $this->operator->value,
            'value' => $this->value,
            'value2' => $this->operator->requiresUpperBound() ? ($this->value2 ?? $this->value) : null,
            'current' => $bag->get($this->fact),
            'met' => $this->matches($bag),
        ];
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
