<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\RunParticipant;
use PHPUnit\Framework\TestCase;

final class RunParticipantTest extends TestCase
{
    public function testNewParticipantHasNoSlots(): void
    {
        $participant = RunParticipant::create('run-1', 'user-1', new \DateTimeImmutable());

        self::assertFalse($participant->hasSlots());
        self::assertSame([], $participant->getGameSlots());
        self::assertNull($participant->getSlot('whatever'));
    }

    public function testReplaceSlotsNumbersOrderAndKeepsOptionalFields(): void
    {
        $participant = RunParticipant::create('run-1', 'user-1', new \DateTimeImmutable());

        $participant->replaceSlots([
            ['slotId' => 's1', 'gameId' => 'g1'],
            ['slotId' => 's2', 'gameId' => 'g2', 'playerYaml' => 'yaml', 'apworldHash' => 'hash'],
        ]);

        self::assertTrue($participant->hasSlots());
        $slots = $participant->getGameSlots();
        self::assertSame(1, $slots[0]['slotOrder']);
        self::assertSame(2, $slots[1]['slotOrder']);
        self::assertArrayNotHasKey('playerYaml', $slots[0]);
        self::assertSame('yaml', $slots[1]['playerYaml'] ?? null);
        self::assertSame('hash', $slots[1]['apworldHash'] ?? null);
    }

    public function testSetSlotPlayerYamlUpdatesMatchingSlot(): void
    {
        $participant = RunParticipant::create('run-1', 'user-1', new \DateTimeImmutable());
        $participant->replaceSlots([['slotId' => 's1', 'gameId' => 'g1']]);

        $participant->setSlotPlayerYaml('s1', 'the-yaml', 'the-hash');

        $slot = $participant->getSlot('s1');
        self::assertNotNull($slot);
        self::assertSame('the-yaml', $slot['playerYaml'] ?? null);
        self::assertSame('the-hash', $slot['apworldHash'] ?? null);
    }

    public function testSetSlotPlayerYamlThrowsForUnknownSlot(): void
    {
        $participant = RunParticipant::create('run-1', 'user-1', new \DateTimeImmutable());
        $participant->replaceSlots([['slotId' => 's1', 'gameId' => 'g1']]);

        $this->expectException(\DomainException::class);
        $participant->setSlotPlayerYaml('missing', 'y', 'h');
    }
}
