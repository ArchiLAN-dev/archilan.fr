<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\SizeSearch;
use PHPUnit\Framework\TestCase;

/**
 * Story 56.7: the size search against a simulated generator (a duration for each option value).
 */
final class SizeSearchTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-23T12:00:00+00:00');
    }

    public function testAGrowingOptionIsHalvedDownToTheTarget(): void
    {
        // Tokens 1..200, one tenth of an hour each; the player asks for 120 (12 h), the target is 4 h.
        $search = $this->search(4.0, [['key' => 'tokens', 'min' => 1, 'max' => 200, 'current' => 120]]);

        $tried = $this->playOut($search, static fn (int $v): float => $v / 10);

        self::assertSame([1, 60, 30, 45, 37], $tried);
        self::assertSame(SizeSearch::STATUS_FOUND, $search->getStatus());
        self::assertSame(['option' => 'tokens', 'from' => 120, 'value' => 37, 'hours' => 3.7], $search->getProposal());
    }

    public function testAShrinkingOptionIsSearchedFromItsMaximum(): void
    {
        // The more removed, the shorter: 20 h at 0, nothing at 200. The player removes 40 (16 h), the target is 5 h.
        $search = $this->search(5.0, [['key' => 'removed', 'min' => 0, 'max' => 200, 'current' => 40]]);

        $tried = $this->playOut($search, static fn (int $v): float => (200 - $v) / 10);

        self::assertSame([0, 200, 120, 160, 140, 150], $tried);
        self::assertSame(SizeSearch::STATUS_FOUND, $search->getStatus());
        self::assertSame(150, $search->getProposal()['value'] ?? null);
    }

    public function testAnOptionThatChangesNothingHandsOverToTheNextOne(): void
    {
        $search = $this->search(4.0, [
            ['key' => 'cosmetic', 'min' => 0, 'max' => 10, 'current' => 5],
            ['key' => 'tokens', 'min' => 1, 'max' => 80, 'current' => 80],
        ]);

        $tried = $this->playOut($search, static fn (int $v, string $key): float => 'cosmetic' === $key ? 12.0 : $v / 10);

        self::assertSame([0, 10, 1, 40], $tried);
        self::assertSame(['option' => 'tokens', 'from' => 80, 'value' => 40, 'hours' => 4.0], $search->getProposal());
    }

    public function testAFailedGenerationGivesUpTheOption(): void
    {
        $search = $this->search(4.0, [['key' => 'tokens', 'min' => 1, 'max' => 200, 'current' => 120]]);

        $tried = $this->playOut($search, static fn (int $v): ?float => null);

        self::assertSame([1], $tried);
        self::assertSame(SizeSearch::STATUS_NOT_FOUND, $search->getStatus());
        self::assertNull($search->getProposal());
    }

    public function testTheSearchStopsAfterSixGenerations(): void
    {
        // Never close enough: from 1 h straight to 30 h.
        $search = $this->search(10.0, [['key' => 'tokens', 'min' => 1, 'max' => 1000, 'current' => 1000]]);

        $tried = $this->playOut($search, static fn (int $v): float => $v < 500 ? 1.0 : 30.0);

        self::assertCount(SizeSearch::MAX_TRIES, $tried);
        self::assertSame(SizeSearch::STATUS_NOT_FOUND, $search->getStatus());
        self::assertCount(SizeSearch::MAX_TRIES, $search->getTries());
    }

    public function testThePlayersOwnValueIsNeverTried(): void
    {
        // Already at the minimum: only the maximum is left to try, and it is longer.
        $search = $this->search(4.0, [['key' => 'tokens', 'min' => 1, 'max' => 10, 'current' => 1]]);

        $tried = $this->playOut($search, static fn (int $v): float => $v * 2.0);

        self::assertSame([10], $tried);
        self::assertSame(SizeSearch::STATUS_NOT_FOUND, $search->getStatus());
    }

    public function testASearchNeedsAnOptionAndATarget(): void
    {
        $this->expectException(\DomainException::class);

        SizeSearch::start('u', 'g', 'r', "name: P\n", 4.0, [], $this->now);
    }

    /**
     * @param list<array{key: string, min: int, max: int, current: int}> $options
     */
    private function search(float $target, array $options): SizeSearch
    {
        return SizeSearch::start('user-1', 'game-1', 'round-1', "name: P\n", $target, $options, $this->now);
    }

    /**
     * Plays the search to its end, as the worker would: each value it asks for is "generated" by `$hours`.
     *
     * @param \Closure(int, string): ?float $hours
     *
     * @return list<int> the values tried, in order
     */
    private function playOut(SizeSearch $search, \Closure $hours): array
    {
        $tried = [];
        while (null !== $next = $search->nextTry($this->now)) {
            $tried[] = $next['value'];
            $search->awaitResult($next['option'], $next['value'], 'job-'.count($tried), $this->now);
            $duration = $hours($next['value'], $next['option']);
            $search->recordResult(null === $duration ? null : (int) round($duration * 10), $duration, $this->now);
        }

        return $tried;
    }
}
