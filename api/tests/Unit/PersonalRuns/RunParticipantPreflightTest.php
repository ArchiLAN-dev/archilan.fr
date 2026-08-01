<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\Entity\RunParticipant;
use PHPUnit\Framework\TestCase;

final class RunParticipantPreflightTest extends TestCase
{
    public function testRecordSlotPreflightStoresVerdict(): void
    {
        $participant = $this->participantWithSlot();

        $ok = $participant->recordSlotPreflight('slot-1', 'passed', '', 'sha-1', new \DateTimeImmutable('2026-07-30T12:00:00+00:00'));

        self::assertTrue($ok);
        $slot = $participant->getSlot('slot-1');
        self::assertNotNull($slot);
        $preflight = $slot['preflight'] ?? null;
        self::assertNotNull($preflight);
        self::assertSame('passed', $preflight['status']);
        self::assertSame('sha-1', $preflight['yamlSha']);
    }

    public function testRecordSlotPreflightUnknownSlotReturnsFalse(): void
    {
        $participant = $this->participantWithSlot();

        self::assertFalse($participant->recordSlotPreflight('ghost', 'passed', '', 'sha-1', new \DateTimeImmutable()));
    }

    public function testSubmitSlotPlayerYamlClearsStaleVerdict(): void
    {
        $participant = $this->participantWithSlot();
        $participant->recordSlotPreflight('slot-1', 'failed', 'Exception: boom', 'sha-1', new \DateTimeImmutable('2026-07-30T12:00:00+00:00'));

        $participant->submitSlotPlayerYaml('slot-1', "name: Jean\ngame: TUNIC\n", 'hash-2');

        $slot = $participant->getSlot('slot-1');
        self::assertNotNull($slot);
        self::assertArrayNotHasKey('preflight', $slot);
    }

    public function testReplaceSlotsKeepsVerdictOfKeptSlots(): void
    {
        $participant = $this->participantWithSlot();
        $participant->recordSlotPreflight('slot-1', 'passed', '', 'sha-1', new \DateTimeImmutable('2026-07-30T12:00:00+00:00'));

        $participant->replaceSlots([
            ['slotId' => 'slot-1', 'gameId' => 'game-1'],
            ['slotId' => 'slot-2', 'gameId' => 'game-2'],
        ]);

        $kept = $participant->getSlot('slot-1');
        self::assertNotNull($kept);
        $keptPreflight = $kept['preflight'] ?? null;
        self::assertNotNull($keptPreflight);
        self::assertSame('passed', $keptPreflight['status']);

        $fresh = $participant->getSlot('slot-2');
        self::assertNotNull($fresh);
        self::assertArrayNotHasKey('preflight', $fresh);
    }

    private function participantWithSlot(): RunParticipant
    {
        $participant = RunParticipant::create('run-1', 'user-1', new \DateTimeImmutable('2026-07-30T10:00:00+00:00'));
        $participant->replaceSlots([['slotId' => 'slot-1', 'gameId' => 'game-1']]);

        return $participant;
    }
}
