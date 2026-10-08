<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\AchievementOperator;
use App\Community\Domain\AchievementRuleGroup;
use App\Community\Domain\Exception\InvalidAchievementRuleException;
use App\Community\Domain\Service\AchievementRuleFactory;
use App\Community\Domain\ValueObject\AchievementRuleCondition;
use App\Community\Domain\ValueObject\MetricBag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AchievementRuleTest extends TestCase
{
    public function testProgressGivesEachConditionItsCurrentValueAndEachGroupWhetherItHolds(): void
    {
        // Story 30.53: runs >= 10 AND (checks between 500 and 2000 OR none of (goals >= 1)).
        $rule = AchievementRuleFactory::fromArray([
            'op' => 'all',
            'rules' => [
                ['fact' => 'runs', 'operator' => '>=', 'value' => 10],
                ['op' => 'any', 'rules' => [
                    ['fact' => 'checks', 'operator' => 'between', 'value' => 500, 'value2' => 2000],
                    ['op' => 'none', 'rules' => [['fact' => 'goals', 'operator' => '>=', 'value' => 1]]],
                ]],
            ],
        ]);

        $progress = $rule->progress($this->bag(['runs' => 4, 'checks' => 2500, 'goals' => 0]));

        self::assertSame(['type' => 'group', 'op' => 'all', 'met' => false], array_slice($progress, 0, 3));
        $rules = $progress['rules'] ?? null;
        self::assertIsArray($rules);
        self::assertSame(
            ['type' => 'condition', 'fact' => 'runs', 'operator' => '>=', 'value' => 10, 'value2' => null, 'current' => 4, 'met' => false],
            $rules[0] ?? null,
        );
        $any = $rules[1] ?? null;
        self::assertIsArray($any);
        self::assertTrue($any['met']);
        $anyRules = $any['rules'] ?? null;
        self::assertIsArray($anyRules);
        $between = $anyRules[0] ?? null;
        self::assertIsArray($between);
        self::assertSame([2000, 2500, false], [$between['value2'], $between['current'], $between['met']]);
        $none = $anyRules[1] ?? null;
        self::assertIsArray($none);
        // « none » holds while its condition does not.
        self::assertTrue($none['met']);
        self::assertSame($rule->matches($this->bag(['runs' => 4, 'checks' => 2500])), $progress['met']);
    }

    #[DataProvider('operatorCases')]
    public function testOperatorEvaluationMatchesExpectation(AchievementOperator $op, int $left, int $value, ?int $value2, bool $expected): void
    {
        $condition = new AchievementRuleCondition(AchievementMetricCatalog::FACT_RUNS, $op, $value, $value2);

        self::assertSame($expected, $condition->matches($this->bag(['runs' => $left])));
    }

    /**
     * @return iterable<string, array{0: AchievementOperator, 1: int, 2: int, 3: int|null, 4: bool}>
     */
    public static function operatorCases(): iterable
    {
        yield '>= true' => [AchievementOperator::GreaterOrEqual, 10, 10, null, true];
        yield '>= false' => [AchievementOperator::GreaterOrEqual, 9, 10, null, false];
        yield '> true' => [AchievementOperator::GreaterThan, 11, 10, null, true];
        yield '> false' => [AchievementOperator::GreaterThan, 10, 10, null, false];
        yield '= true' => [AchievementOperator::Equal, 5, 5, null, true];
        yield '= false' => [AchievementOperator::Equal, 6, 5, null, false];
        yield '!= true' => [AchievementOperator::NotEqual, 6, 5, null, true];
        yield '!= false' => [AchievementOperator::NotEqual, 5, 5, null, false];
        yield '<= true' => [AchievementOperator::LessOrEqual, 5, 5, null, true];
        yield '<= false' => [AchievementOperator::LessOrEqual, 6, 5, null, false];
        yield '< true' => [AchievementOperator::LessThan, 4, 5, null, true];
        yield '< false' => [AchievementOperator::LessThan, 5, 5, null, false];
        yield 'between inside' => [AchievementOperator::Between, 5, 1, 10, true];
        yield 'between lower bound' => [AchievementOperator::Between, 1, 1, 10, true];
        yield 'between above' => [AchievementOperator::Between, 11, 1, 10, false];
    }

    public function testUnknownFactReadsAsZero(): void
    {
        $condition = new AchievementRuleCondition('neverHeardOf', AchievementOperator::GreaterOrEqual, 1, null);

        self::assertFalse($condition->matches($this->bag([])));
    }

    public function testNestedAllAnyNoneCompose(): void
    {
        // (runs >= 10 AND distinctGames >= 5) OR events >= 3, with a NONE guard.
        $rule = new AchievementRuleGroup(AchievementRuleGroup::OP_ANY, [
            new AchievementRuleGroup(AchievementRuleGroup::OP_ALL, [
                new AchievementRuleCondition('runs', AchievementOperator::GreaterOrEqual, 10, null),
                new AchievementRuleCondition('distinctGames', AchievementOperator::GreaterOrEqual, 5, null),
            ]),
            new AchievementRuleGroup(AchievementRuleGroup::OP_NONE, [
                new AchievementRuleCondition('runs', AchievementOperator::GreaterThan, 100, null),
            ]),
        ]);

        // Second branch is NONE(runs>100) → true when runs<=100, so the OR matches.
        self::assertTrue($rule->matches($this->bag(['runs' => 1, 'distinctGames' => 1])));
        // First branch matches outright.
        self::assertTrue($rule->matches($this->bag(['runs' => 10, 'distinctGames' => 5])));
        // Neither branch: runs>100 makes NONE false, and the AND fails on distinctGames.
        self::assertFalse($rule->matches($this->bag(['runs' => 200, 'distinctGames' => 1])));
    }

    public function testJsonRoundTripPreservesStructure(): void
    {
        $data = [
            'op' => 'all',
            'rules' => [
                ['fact' => 'runs', 'operator' => '>=', 'value' => 10],
                [
                    'op' => 'any',
                    'rules' => [
                        ['fact' => 'goals', 'operator' => 'between', 'value' => 1, 'value2' => 5],
                    ],
                ],
            ],
        ];

        $rebuilt = AchievementRuleFactory::fromArray($data)->toArray();

        self::assertSame($data, $rebuilt);
    }

    public function testFactoryRejectsTooDeepNesting(): void
    {
        $this->expectException(InvalidAchievementRuleException::class);

        AchievementRuleFactory::fromArray($this->nest(AchievementRuleFactory::MAX_DEPTH + 1));
    }

    public function testFactoryAcceptsMaxDepth(): void
    {
        $rule = AchievementRuleFactory::fromArray($this->nest(AchievementRuleFactory::MAX_DEPTH));

        self::assertInstanceOf(AchievementRuleGroup::class, $rule);
    }

    public function testFactoryRejectsEmptyGroup(): void
    {
        $this->expectException(InvalidAchievementRuleException::class);

        AchievementRuleFactory::fromArray(['op' => 'all', 'rules' => []]);
    }

    public function testFactoryRejectsUnknownFact(): void
    {
        $this->expectException(InvalidAchievementRuleException::class);

        AchievementRuleFactory::fromArray(['op' => 'all', 'rules' => [['fact' => 'nope', 'operator' => '>=', 'value' => 1]]]);
    }

    public function testFactoryRejectsNonGroupRoot(): void
    {
        $this->expectException(InvalidAchievementRuleException::class);

        AchievementRuleFactory::fromArray(['fact' => 'runs', 'operator' => '>=', 'value' => 1]);
    }

    public function testFactoryRejectsBetweenWithoutUpperBound(): void
    {
        $this->expectException(InvalidAchievementRuleException::class);

        AchievementRuleFactory::fromArray(['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => 'between', 'value' => 5]]]);
    }

    /**
     * @param array<string, int> $facts
     */
    private function bag(array $facts): MetricBag
    {
        return new MetricBag($facts);
    }

    /**
     * A chain of `depth` nested ALL groups ending in one condition.
     *
     * @return array<string, mixed>
     */
    private function nest(int $depth): array
    {
        $node = ['fact' => 'runs', 'operator' => '>=', 'value' => 1];
        for ($i = 0; $i < $depth; ++$i) {
            $node = ['op' => 'all', 'rules' => [$node]];
        }

        return $node;
    }
}
