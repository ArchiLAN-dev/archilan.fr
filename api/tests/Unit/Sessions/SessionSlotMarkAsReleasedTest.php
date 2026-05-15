<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\SessionSlot;
use PHPUnit\Framework\TestCase;

final class SessionSlotMarkAsReleasedTest extends TestCase
{
    private function makeSlot(): SessionSlot
    {
        return SessionSlot::create('slot-id', 'session-id', 'registration-id', 'game-id', 'Player1', 0);
    }

    public function testWasReleasedDefaultsFalse(): void
    {
        $slot = $this->makeSlot();
        self::assertFalse($slot->isWasReleased());
    }

    public function testMarkAsReleasedSetsFlag(): void
    {
        $slot = $this->makeSlot();
        $slot->markAsReleased();
        self::assertTrue($slot->isWasReleased());
    }

    public function testMarkAsReleasedIsNoopWhenGoalAlreadyReached(): void
    {
        $slot = $this->makeSlot();
        $slot->setGoalReachedAt(new \DateTimeImmutable());
        $slot->markAsReleased();
        self::assertFalse($slot->isWasReleased());
    }

    public function testMarkAsReleasedIdempotent(): void
    {
        $slot = $this->makeSlot();
        $slot->markAsReleased();
        $slot->markAsReleased();
        self::assertTrue($slot->isWasReleased());
    }

    public function testPayloadIncludesWasReleased(): void
    {
        $slot = $this->makeSlot();
        $payload = $slot->payload();
        self::assertArrayHasKey('wasReleased', $payload);
        self::assertFalse($payload['wasReleased']);

        $slot->markAsReleased();
        self::assertTrue($slot->payload()['wasReleased']);
    }
}
