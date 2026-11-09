<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\Service\DependencyRisk;
use App\Sessions\Domain\Service\SpoilerPlaythrough;
use App\Sessions\Domain\ValueObject\PlaythroughStep;
use PHPUnit\Framework\TestCase;

/** Story 52.7: the playthrough of a spoiler, and the dependencies the presence windows put at risk. */
final class DependencyRiskTest extends TestCase
{
    private const string SPOILER = <<<'TXT'
        Archipelago Version 0.6.8  -  Seed: 123

        Locations:

        Link's House (Alice): Lamp (Bob Le Grand)

        Playthrough:

        0: {
          Progressive Sword (Alice)
        }
        1: {
          Link's House (Alice): Lamp (Bob Le Grand)
          Chest: Upper (Bob Le Grand): Hookshot (Alice)
        }
        2: {
          Kakariko (Shop 1) (Bob Le Grand): Bow (Alice)
        }

        Paths:

        Lamp (Bob Le Grand): [...]
        TXT;

    public function testThePlaythroughIsReadOnThePlayersNames(): void
    {
        $steps = SpoilerPlaythrough::parse(self::SPOILER, ['Bob', 'Alice', 'Bob Le Grand']);

        self::assertNotNull($steps);
        self::assertEquals([
            new PlaythroughStep(1, 'Alice', "Link's House", 'Bob Le Grand', 'Lamp'),
            new PlaythroughStep(1, 'Bob Le Grand', 'Chest: Upper', 'Alice', 'Hookshot'),
            new PlaythroughStep(2, 'Bob Le Grand', 'Kakariko (Shop 1)', 'Alice', 'Bow'),
        ], $steps);
    }

    public function testASpoilerWithoutPlaythroughSaysSo(): void
    {
        self::assertNull(SpoilerPlaythrough::parse("Locations:\n\nA (Alice): B (Bob)\n", ['Alice', 'Bob']));
    }

    public function testTheEventIsCutAtNoonAndMidnight(): void
    {
        $halves = DependencyRisk::halfDays(new \DateTimeImmutable('2026-11-14T10:00:00+01:00'), new \DateTimeImmutable('2026-11-15T18:00:00+01:00'));

        self::assertSame(
            ['10:00-12:00', '12:00-00:00', '00:00-12:00', '12:00-18:00'],
            array_map(static fn (array $h): string => $h[0]->format('H:i').'-'.$h[1]->format('H:i'), $halves),
        );
    }

    public function testAnItemInTheWorldOfSomeoneAbsentWhileTheReceiverIsThereIsAtRisk(): void
    {
        $halves = $this->weekend();
        // Bob only comes on Sunday; Alice is there all along.
        $risks = DependencyRisk::assess($this->steps(), ['Alice' => 'alice', 'Bob' => 'bob'], ['bob' => [$this->sunday()]], $halves);

        self::assertCount(2, $risks);
        self::assertSame(['Hookshot', 'Bow'], array_map(static fn ($r): string => $r->item, $risks));
        self::assertSame(['alice', 'bob'], [$risks[0]->waiting, $risks[0]->holding]);
        self::assertSame([0, 1], $risks[0]->halfDays, 'Saturday morning and afternoon');
    }

    public function testNoRiskWhenThePresencesOverlap(): void
    {
        $risks = DependencyRisk::assess($this->steps(), ['Alice' => 'alice', 'Bob' => 'bob'], ['alice' => [$this->sunday()], 'bob' => [$this->sunday()]], $this->weekend());

        self::assertSame([], $risks);
    }

    public function testNoWindowsAtAllMeansNoRisk(): void
    {
        self::assertSame([], DependencyRisk::assess($this->steps(), ['Alice' => 'alice', 'Bob' => 'bob'], [], $this->weekend()));
    }

    public function testOnlyTheFirstSpheresOfTheReceiverCount(): void
    {
        $risks = DependencyRisk::assess($this->steps(), ['Alice' => 'alice', 'Bob' => 'bob'], ['bob' => [$this->sunday()]], $this->weekend(), 1);

        self::assertSame(['Hookshot'], array_map(static fn ($r): string => $r->item, $risks), 'Bow is in her second sphere');
    }

    public function testTwoSlotsOfOnePersonNeverDependOnEachOther(): void
    {
        $risks = DependencyRisk::assess($this->steps(), ['Alice' => 'alice', 'Bob' => 'alice'], ['alice' => [$this->sunday()]], $this->weekend());

        self::assertSame([], $risks);
    }

    /**
     * @return list<PlaythroughStep>
     */
    private function steps(): array
    {
        return [
            new PlaythroughStep(1, 'Bob', 'Chest', 'Alice', 'Hookshot'),
            new PlaythroughStep(1, 'Alice', 'House', 'Bob', 'Lamp'),
            new PlaythroughStep(3, 'Bob', 'Shop', 'Alice', 'Bow'),
        ];
    }

    /**
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    private function weekend(): array
    {
        return DependencyRisk::halfDays(new \DateTimeImmutable('2026-11-14T09:00:00+01:00'), new \DateTimeImmutable('2026-11-15T20:00:00+01:00'));
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function sunday(): array
    {
        return [new \DateTimeImmutable('2026-11-15T09:00:00+01:00'), new \DateTimeImmutable('2026-11-15T20:00:00+01:00')];
    }
}
