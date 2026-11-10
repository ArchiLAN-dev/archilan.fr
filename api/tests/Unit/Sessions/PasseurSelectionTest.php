<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\Service\PasseurSelection;
use App\Sessions\Domain\ValueObject\PasseurItem;
use PHPUnit\Framework\TestCase;

/** Story 52.3: the chests the Passeur may open, and their order. */
final class PasseurSelectionTest extends TestCase
{
    public function testAProgressionItemInAnyOtherWorldIsACandidate(): void
    {
        $claw = self::item(finder: 2, location: 401, itemId: 101, sphere: 3);
        $atCarol = self::item(finder: 3, location: 501, itemId: 103, sphere: 1);

        // Story 52.9: whether the player of that world is there or not.
        self::assertSame([$atCarol, $claw], PasseurSelection::candidates([$claw, $atCarol]));
    }

    public function testFillerIsLeftOut(): void
    {
        $geo = self::item(finder: 2, location: 402, itemId: 102, sphere: 1, progression: false);

        self::assertSame([], PasseurSelection::candidates([$geo]));
    }

    public function testOneCopyOfAnItemTheLatestInThePlaythrough(): void
    {
        $early = self::item(finder: 2, location: 401, itemId: 101, sphere: 1);
        $late = self::item(finder: 4, location: 650, itemId: 101, sphere: 6);
        $untracked = self::item(finder: 2, location: 499, itemId: 101, sphere: null);

        self::assertSame([$late], PasseurSelection::candidates([$early, $untracked, $late]));
    }

    public function testWithoutMeasureTheEarliestSphereComesFirstAndAnUntrackedWorldLast(): void
    {
        $untracked = self::item(finder: 2, location: 300, itemId: 101, sphere: null);
        $late = self::item(finder: 2, location: 401, itemId: 102, sphere: 5);
        $early = self::item(finder: 2, location: 402, itemId: 103, sphere: 2);
        $earlyToo = self::item(finder: 2, location: 400, itemId: 104, sphere: 2);

        self::assertSame([$earlyToo, $early, $late, $untracked], PasseurSelection::candidates([$untracked, $late, $early, $earlyToo]));
    }

    public function testTheMeasuredGainRanksFirstThenTheLocationId(): void
    {
        $small = self::item(finder: 2, location: 401, itemId: 101, sphere: 1);
        $big = self::item(finder: 2, location: 405, itemId: 102, sphere: 4);
        $bigToo = self::item(finder: 2, location: 403, itemId: 103, sphere: 6);
        $unmeasured = self::item(finder: 2, location: 100, itemId: 104, sphere: 0);

        $ranked = PasseurSelection::byRank([$small, $big, $bigToo, $unmeasured], ['2:401' => 1, '2:405' => 7, '2:403' => 7]);

        self::assertSame([$bigToo, $big, $small, $unmeasured], $ranked);
    }

    private static function item(int $finder, int $location, int $itemId, ?int $sphere, bool $progression = true): PasseurItem
    {
        return new PasseurItem($finder, 'P'.$finder, $location, 'L'.$location, $itemId, 'I'.$itemId, $progression, $sphere);
    }
}
