<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\Entity\RunParticipant;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.7: a slot of a run not yet launched follows its game to a new apworld.
 */
final class RunParticipantUpgradeSlotTest extends TestCase
{
    public function testUpgradeReplacesHashAndYamlAndDropsTheStaleVerdict(): void
    {
        $participant = $this->participant();

        $participant->upgradeSlotApworld('slot-1', 'hash-new', "game: Crystal Project\n# new default\n", []);

        $slot = $participant->getSlot('slot-1');
        self::assertSame('hash-new', $slot['apworldHash'] ?? null);
        self::assertSame("game: Crystal Project\n# new default\n", $slot['playerYaml'] ?? null);
        self::assertArrayNotHasKey('preflight', $slot, 'the verdict was for the old apworld');
        self::assertArrayNotHasKey('needsReview', $slot);
    }

    public function testUpgradeKeepsThePlayerYamlWhenNoneIsGiven(): void
    {
        $participant = $this->participant();

        $participant->upgradeSlotApworld('slot-1', 'hash-new', null, []);

        self::assertSame("game: Crystal Project\ngoal: true_astley\n", $participant->getSlot('slot-1')['playerYaml'] ?? null);
    }

    public function testUpgradeMarksTheSlotForReviewWithTheReasons(): void
    {
        $participant = $this->participant();

        $participant->upgradeSlotApworld('slot-1', 'hash-new', null, ['« goal » : la valeur « moon » n\'est plus acceptée.']);

        self::assertSame(['« goal » : la valeur « moon » n\'est plus acceptée.'], $participant->getSlot('slot-1')['needsReview'] ?? null);
    }

    public function testTheNextSaveOfTheSlotClearsTheReview(): void
    {
        $participant = $this->participant();
        $participant->upgradeSlotApworld('slot-1', 'hash-new', null, ['« goal » : la valeur « moon » n\'est plus acceptée.']);

        $participant->submitSlotPlayerYaml('slot-1', "game: Crystal Project\ngoal: astley\n", 'hash-new');

        self::assertArrayNotHasKey('needsReview', $participant->getSlot('slot-1') ?? []);
    }

    public function testAReviewSurvivesAReorderOfTheSelection(): void
    {
        $participant = $this->participant();
        $participant->upgradeSlotApworld('slot-1', 'hash-new', null, ['reason']);

        $participant->replaceSlots([
            ['slotId' => 'slot-2', 'gameId' => 'game-2'],
            ['slotId' => 'slot-1', 'gameId' => 'game-1', 'playerYaml' => "game: Crystal Project\ngoal: true_astley\n", 'apworldHash' => 'hash-new'],
        ]);

        self::assertSame(['reason'], $participant->getSlot('slot-1')['needsReview'] ?? null);
    }

    public function testUpgradingAnUnknownSlotIsAnError(): void
    {
        $this->expectException(\DomainException::class);

        $this->participant()->upgradeSlotApworld('unknown', 'hash-new', null, []);
    }

    private function participant(): RunParticipant
    {
        $participant = RunParticipant::create('run-1', 'user-1', new \DateTimeImmutable('2026-09-20'));
        $participant->replaceSlots([
            ['slotId' => 'slot-1', 'gameId' => 'game-1', 'playerYaml' => "game: Crystal Project\ngoal: true_astley\n", 'apworldHash' => 'hash-old'],
        ]);
        $participant->recordSlotPreflight('slot-1', 'failed', 'Fill.FillError', 'sha', new \DateTimeImmutable('2026-09-21'));

        return $participant;
    }
}
