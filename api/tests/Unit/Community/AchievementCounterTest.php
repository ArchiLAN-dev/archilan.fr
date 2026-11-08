<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Support\CounterNameSearch;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\Entity\AchievementCounter;
use App\Community\Domain\Enum\AchievementCounterKind;
use App\Community\Domain\Enum\AchievementCounterMode;
use PHPUnit\Framework\TestCase;

/**
 * Story 30.57: a counter keeps its names trimmed and unique, the fact `counter:{id}` is a valid criterion, and the
 * name search filters on what was typed.
 */
final class AchievementCounterTest extends TestCase
{
    public function testTheNamesAreTrimmedAndUniqueInTheirOrder(): void
    {
        $counter = $this->counter(['  Water Temple Morpha Heart', 'LH Sun', 'Water Temple Morpha Heart', '']);

        self::assertSame(['Water Temple Morpha Heart', 'LH Sun'], $counter->getTargets());
    }

    public function testACounterNeedsALabelAGameAndANameAtLeast(): void
    {
        foreach ([['', 'g-1', ['A']], ['Morpha', ' ', ['A']], ['Morpha', 'g-1', [' ']]] as [$label, $game, $names]) {
            try {
                AchievementCounter::create('c-1', $label, $game, AchievementCounterKind::Location, $names, AchievementCounterMode::Total, false, new \DateTimeImmutable());
                self::fail('A counter without '.('' === $label ? 'label' : ('' === trim($game) ? 'game' : 'name')).' was created.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testACounterAimsAtFiftyNamesAtMost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->counter(array_map(static fn (int $i): string => 'Location '.$i, range(1, AchievementCounter::MAX_TARGETS + 1)));
    }

    public function testTheCounterFactIsAValidCriterion(): void
    {
        self::assertTrue(AchievementMetricCatalog::isValidFact('counter:c-1'));
        self::assertFalse(AchievementMetricCatalog::isValidFact('counter:'));
        self::assertSame('c-1', AchievementMetricCatalog::counterIdFromFact('counter:c-1'));
        self::assertNull(AchievementMetricCatalog::counterIdFromFact('checks'));
        self::assertSame(['c-1', 'c-2'], AchievementMetricCatalog::counterIdsIn(['op' => 'all', 'rules' => [
            ['fact' => 'counter:c-1', 'operator' => '>=', 'value' => 1],
            ['op' => 'any', 'rules' => [['fact' => 'counter:c-2', 'operator' => '>=', 'value' => 5], ['fact' => 'counter:c-1', 'operator' => '>=', 'value' => 10], ['fact' => 'checks', 'operator' => '>=', 'value' => 3]]],
        ]]));
    }

    public function testTheSearchIgnoresCaseAndAccentsAndWantsEveryWord(): void
    {
        $names = ['Water Temple Morpha Heart', 'Water Temple Boss Key Chest', 'Deku Tree Queen Gohma Heart', 'Heart Piece Grotto'];

        self::assertSame(['Water Temple Morpha Heart'], CounterNameSearch::search($names, 'WATER mórpha'));
        self::assertSame(['Heart Piece Grotto', 'Deku Tree Queen Gohma Heart', 'Water Temple Morpha Heart'], CounterNameSearch::search($names, 'heart'), 'starting with the text, then a word starting with it');
        self::assertSame([], CounterNameSearch::search($names, 'h'));
        self::assertSame(['Water Temple Boss Key Chest'], CounterNameSearch::search($names, 'water', ['Water Temple Morpha Heart']));
        self::assertCount(2, CounterNameSearch::search($names, 'heart', [], 2));
    }

    /**
     * @param list<string> $names
     */
    private function counter(array $names): AchievementCounter
    {
        return AchievementCounter::create('c-1', 'Morpha', 'g-1', AchievementCounterKind::Location, $names, AchievementCounterMode::Total, false, new \DateTimeImmutable());
    }
}
