<?php

declare(strict_types=1);

namespace App\Tests\Unit\Registrations;

use App\Registrations\Domain\Entity\Registration;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.7: an event registration's slot follows its game to a new apworld before the event is
 * generated.
 */
final class RegistrationUpgradeSlotTest extends TestCase
{
    public function testUpgradeReplacesHashAndYaml(): void
    {
        $registration = $this->registration();

        $registration->upgradeSlotApworld('slot-1', 'hash-new', "new default\n", [], new \DateTimeImmutable('2026-09-26'));

        $slot = $registration->getGameSlots()[0];
        self::assertSame('hash-new', $slot['apworldHash'] ?? null);
        self::assertSame("new default\n", $slot['playerYaml'] ?? null);
        self::assertArrayNotHasKey('needsReview', $slot);
    }

    public function testUpgradeMarksForReviewAndTheNextSaveClearsIt(): void
    {
        $registration = $this->registration();
        $registration->upgradeSlotApworld('slot-1', 'hash-new', null, ['reason'], new \DateTimeImmutable('2026-09-26'));

        self::assertSame(['reason'], $registration->getGameSlots()[0]['needsReview'] ?? null);
        self::assertSame("game: Crystal Project\ngoal: moon\n", $registration->getGameSlots()[0]['playerYaml'] ?? null);

        $registration->submitSlotPlayerYaml('slot-1', "game: Crystal Project\ngoal: astley\n", 'hash-new', new \DateTimeImmutable('2026-09-27'));

        self::assertArrayNotHasKey('needsReview', $registration->getGameSlots()[0]);
    }

    public function testAReviewSurvivesAReorderOfTheSelection(): void
    {
        $registration = $this->registration();
        $registration->upgradeSlotApworld('slot-1', 'hash-new', null, ['reason'], new \DateTimeImmutable('2026-09-26'));

        $registration->replaceSlots([
            ['slotId' => 'slot-2', 'gameId' => 'game-2'],
            ['slotId' => 'slot-1', 'gameId' => 'game-1', 'playerYaml' => "game: Crystal Project\ngoal: moon\n", 'apworldHash' => 'hash-new'],
        ], new \DateTimeImmutable('2026-09-27'));

        self::assertSame(['reason'], $registration->getGameSlots()[1]['needsReview'] ?? null);
    }

    private function registration(): Registration
    {
        $now = new \DateTimeImmutable('2026-09-20');

        return new Registration('reg-1', 'event-1', 'user-1', Registration::STATUS_RESERVED, $now, $now, [
            ['slotId' => 'slot-1', 'gameId' => 'game-1', 'slotOrder' => 1, 'apworldHash' => 'hash-old', 'playerYaml' => "game: Crystal Project\ngoal: moon\n"],
        ]);
    }
}
