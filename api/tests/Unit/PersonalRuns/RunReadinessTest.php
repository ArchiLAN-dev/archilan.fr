<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Service\RunReadiness;
use PHPUnit\Framework\TestCase;

/**
 * Story 16.23: who can say they are ready, the count, and what drops the status.
 */
final class RunReadinessTest extends TestCase
{
    public function testAGameOrACoPlayedSlotMakesAPlayerEligible(): void
    {
        $withGame = $this->participant('a', ['s1' => 'g1']);
        $coPlayer = $this->participant('b');
        $watcher = $this->participant('c');

        $eligible = RunReadiness::eligible([$withGame, $coPlayer, $watcher], ['b']);

        self::assertSame(['a' => true, 'b' => true], $eligible);
    }

    public function testTheCountLeavesOutWhoCannotBeReady(): void
    {
        $ready = $this->participant('a', ['s1' => 'g1']);
        $ready->markReady(new \DateTimeImmutable());
        $notYet = $this->participant('b', ['s2' => 'g1']);
        $staleWatcher = $this->participant('c');
        $staleWatcher->markReady(new \DateTimeImmutable());
        $all = [$ready, $notYet, $staleWatcher];

        self::assertSame(['ready' => 1, 'total' => 2], RunReadiness::count($all, RunReadiness::eligible($all, [])));
    }

    public function testAddingOrRemovingAGameDropsTheStatusReorderingDoesNot(): void
    {
        $participant = $this->participant('a', ['s1' => 'g1', 's2' => 'g2']);
        $participant->markReady(new \DateTimeImmutable());

        $participant->replaceSlots([['slotId' => 's2', 'gameId' => 'g2'], ['slotId' => 's1', 'gameId' => 'g1']]);
        self::assertTrue($participant->isReady());

        $participant->replaceSlots([['slotId' => 's2', 'gameId' => 'g2']]);
        self::assertFalse($participant->isReady());
    }

    public function testANewConfigDropsTheStatusTheSameOneDoesNot(): void
    {
        $participant = $this->participant('a', ['s1' => 'g1']);
        $participant->submitSlotPlayerYaml('s1', "name: A\n", 'hash');
        $participant->markReady(new \DateTimeImmutable());

        $participant->submitSlotPlayerYaml('s1', "name: A\n", 'hash');
        self::assertTrue($participant->isReady());

        $participant->submitSlotPlayerYaml('s1', "name: B\n", 'hash');
        self::assertFalse($participant->isReady());
    }

    /**
     * @param array<string, string> $slots slot id => game id
     */
    private function participant(string $userId, array $slots = []): RunParticipant
    {
        $participant = RunParticipant::create('run', $userId, new \DateTimeImmutable('2026-10-17T10:00:00+00:00'));
        $rows = [];
        foreach ($slots as $slotId => $gameId) {
            $rows[] = ['slotId' => $slotId, 'gameId' => $gameId];
        }
        $participant->replaceSlots($rows);

        return $participant;
    }
}
