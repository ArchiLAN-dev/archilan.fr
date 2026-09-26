<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Service\CatalogSweepPlanner;
use App\GameSelection\Domain\ValueObject\SweepCandidate;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.9: which apworlds the nightly sweep retests, in which order.
 */
final class CatalogSweepPlannerTest extends TestCase
{
    private const string IMAGE = 'ghcr.io/archilan-dev/archipelago:0.16.1';
    private const string IMAGE_ID = 'sha256:current';

    public function testVerdictsFromAnotherImageComeFirst(): void
    {
        $plan = CatalogSweepPlanner::plan([
            $this->onCurrent('current', '2026-01-01'),
            $this->candidate('other', 'ghcr.io/archilan-dev/archipelago:0.16.0', 'sha256:old', '2026-09-20'),
        ], self::IMAGE, self::IMAGE_ID, 25);

        self::assertSame(['other', 'current'], $plan);
    }

    public function testUnknownImageCountsAsAnotherImage(): void
    {
        // A verdict older than story 38.8, or the same tag without both ids: nothing says it holds now.
        $plan = CatalogSweepPlanner::plan([
            $this->onCurrent('current', '2026-01-01'),
            $this->candidate('legacy', null, null, '2026-09-20'),
            $this->candidate('same-tag-no-id', self::IMAGE, null, '2026-09-21'),
        ], self::IMAGE, self::IMAGE_ID, 25);

        self::assertSame(['legacy', 'same-tag-no-id', 'current'], $plan);
    }

    public function testNeverTestedComeBeforeOldVerdictsOnTheCurrentImage(): void
    {
        $plan = CatalogSweepPlanner::plan([
            $this->onCurrent('current', '2020-01-01'),
            new SweepCandidate('g-never', 'never', null, null, null, false),
        ], self::IMAGE, self::IMAGE_ID, 25);

        self::assertSame(['never', 'current'], $plan);
    }

    public function testOldestVerdictsFirstOnTheCurrentImage(): void
    {
        $plan = CatalogSweepPlanner::plan([
            $this->onCurrent('recent', '2026-09-20'),
            $this->onCurrent('old', '2026-01-01'),
            $this->onCurrent('middle', '2026-05-01'),
        ], self::IMAGE, self::IMAGE_ID, 25);

        self::assertSame(['old', 'middle', 'recent'], $plan);
    }

    public function testOldestVerdictsFirstAmongOtherImagesToo(): void
    {
        $plan = CatalogSweepPlanner::plan([
            $this->candidate('other-recent', null, null, '2026-09-20'),
            $this->candidate('other-old', null, null, '2026-01-01'),
        ], self::IMAGE, self::IMAGE_ID, 25);

        self::assertSame(['other-old', 'other-recent'], $plan);
    }

    public function testExcludedGamesAreNeverPlanned(): void
    {
        $plan = CatalogSweepPlanner::plan([
            new SweepCandidate('g-1', 'excluded', null, null, null, true),
            $this->onCurrent('kept', '2026-01-01'),
        ], self::IMAGE, self::IMAGE_ID, 25);

        self::assertSame(['kept'], $plan);
    }

    public function testBatchSizeIsRespected(): void
    {
        $candidates = [];
        foreach (range(1, 30) as $i) {
            $candidates[] = $this->onCurrent('h'.$i, sprintf('2026-01-%02d', $i));
        }

        $plan = CatalogSweepPlanner::plan($candidates, self::IMAGE, self::IMAGE_ID, 25);

        self::assertCount(25, $plan);
        self::assertSame('h1', $plan[0]);
    }

    public function testAnApworldServedByTwoGamesIsPlannedOnce(): void
    {
        $plan = CatalogSweepPlanner::plan([
            new SweepCandidate('g-1', 'shared', null, null, null, false),
            new SweepCandidate('g-2', 'shared', null, null, null, false),
        ], self::IMAGE, self::IMAGE_ID, 25);

        self::assertSame(['shared'], $plan);
    }

    private function onCurrent(string $hash, string $checkedAt): SweepCandidate
    {
        return $this->candidate($hash, self::IMAGE, self::IMAGE_ID, $checkedAt);
    }

    private function candidate(string $hash, ?string $image, ?string $imageId, string $checkedAt): SweepCandidate
    {
        return new SweepCandidate('g-'.$hash, $hash, $image, $imageId, new \DateTimeImmutable($checkedAt), false);
    }
}
