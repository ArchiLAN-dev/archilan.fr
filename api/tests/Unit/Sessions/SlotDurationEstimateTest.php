<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Query\GamePaceQueryInterface;
use App\Sessions\Application\Query\PaceSample;
use App\Sessions\Application\Service\SlotDurationEstimate;
use App\Sessions\Application\Support\PaceSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Story 56.2: a game's pace (median from three slots, then the admin's estimate), a player's factor (pulled toward 1,
 * bounded) and a slot's duration with its source.
 */
final class SlotDurationEstimateTest extends TestCase
{
    public function testTheMeasuredPaceIsTheMedianOfTheSlotsAtGoal(): void
    {
        $estimate = $this->estimate([
            new PaceSample('hk', ['a'], 40.0),
            new PaceSample('hk', ['b'], 100.0),
            new PaceSample('hk', ['c'], 60.0),
            new PaceSample('hk', ['d'], 50.0),
        ]);

        $duration = $estimate->forSlot('hk', [], 330);

        self::assertSame(PaceSource::Measured, $duration->source);
        self::assertSame(4, $duration->slots);
        self::assertSame(55.0, $duration->checksPerHour);
        self::assertEqualsWithDelta(6.0, $duration->hours, 0.001);
    }

    public function testBelowThreeSlotsThePaceFallsBackOnTheAdminEstimate(): void
    {
        $estimate = $this->estimate([new PaceSample('oot', ['a'], 90.0), new PaceSample('oot', ['b'], 110.0)], ['oot' => 50]);

        $duration = $estimate->forSlot('oot', [], 500);

        self::assertSame(PaceSource::Admin, $duration->source);
        self::assertSame(0, $duration->slots);
        self::assertSame(50.0, $duration->checksPerHour);
        self::assertEqualsWithDelta(10.0, $duration->hours, 0.001);
    }

    public function testWithoutEitherThePaceIsUnknownAndTheSlotHasNoDuration(): void
    {
        $duration = $this->estimate([new PaceSample('tunic', ['a'], 90.0)])->forSlot('tunic', ['a'], 300);

        self::assertSame(PaceSource::Unknown, $duration->source);
        self::assertNull($duration->hours);
        self::assertNull($duration->checksPerHour);
    }

    public function testAnUnmeasuredSlotSizeGivesNoDuration(): void
    {
        $duration = $this->estimate([], ['oot' => 50])->forSlot('oot', [], null);

        self::assertSame(PaceSource::Admin, $duration->source);
        self::assertNull($duration->hours);
    }

    public function testANewcomerPlaysAtTheGamePace(): void
    {
        $estimate = $this->estimate($this->hollowKnightAt(50.0));

        self::assertSame(1.0, $estimate->playerFactor('nobody'));
    }

    public function testAPlayerFactorIsPulledTowardOneByTheNumberOfTheirSlots(): void
    {
        // The game plays at 50 an hour; "fast" played one slot at 100 (ratio 2): 1 + (2 - 1) x 1 / 4.
        $estimate = $this->estimate([...$this->hollowKnightAt(50.0), new PaceSample('hk', ['fast'], 100.0)]);
        self::assertEqualsWithDelta(1.25, $estimate->playerFactor('fast'), 0.001);

        // Three slots at ratio 2: 1 + 1 x 3 / 6.
        $estimate = $this->estimate([
            ...$this->hollowKnightAt(50.0),
            new PaceSample('hk', ['fast'], 100.0),
            new PaceSample('hk', ['fast'], 100.0),
            new PaceSample('hk', ['fast'], 100.0),
        ]);
        self::assertEqualsWithDelta(1.5, $estimate->playerFactor('fast'), 0.001);
    }

    public function testAPlayerFactorStaysWithinItsBounds(): void
    {
        $samples = $this->hollowKnightAt(50.0);
        for ($i = 0; $i < 30; ++$i) {
            $samples[] = new PaceSample('hk', ['rocket'], 1000.0);
            $samples[] = new PaceSample('hk', ['snail'], 1.0);
        }
        $estimate = $this->estimate($samples);

        self::assertSame(SlotDurationEstimate::MAX_FACTOR, $estimate->playerFactor('rocket'));
        self::assertSame(SlotDurationEstimate::MIN_FACTOR, $estimate->playerFactor('snail'));
    }

    public function testTheFactorOfASharedSlotIsTheMeanOfItsPlayers(): void
    {
        $estimate = $this->estimate([...$this->hollowKnightAt(50.0), new PaceSample('hk', ['fast'], 100.0)]);

        // 1.25 for "fast", 1 for a newcomer: 1.125.
        $duration = $estimate->forSlot('hk', ['fast', 'nobody'], 225);

        self::assertEqualsWithDelta(1.125, $duration->playerFactor, 0.001);
        self::assertEqualsWithDelta(225 / (50.0 * 1.125), $duration->hours, 0.001);
    }

    /** @return list<PaceSample> */
    private function hollowKnightAt(float $pace): array
    {
        return [
            new PaceSample('hk', ['a'], $pace),
            new PaceSample('hk', ['b'], $pace),
            new PaceSample('hk', ['c'], $pace),
            new PaceSample('hk', ['d'], $pace),
            new PaceSample('hk', ['e'], $pace),
        ];
    }

    /**
     * @param list<PaceSample>   $samples
     * @param array<string, int> $admin
     */
    private function estimate(array $samples, array $admin = []): SlotDurationEstimate
    {
        $query = self::createStub(GamePaceQueryInterface::class);
        $query->method('samples')->willReturn($samples);
        $query->method('adminEstimates')->willReturn($admin);

        return new SlotDurationEstimate($query, new ArrayAdapter());
    }
}
