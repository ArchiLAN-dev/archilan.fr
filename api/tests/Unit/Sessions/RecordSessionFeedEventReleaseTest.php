<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Command\RecordSessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionFeedEventRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Story 32.14: a release or collect the player makes themselves (`!release` / `!collect` in their client)
 * excludes their slot from the stats, as the admin's `!admin /release` already did. The Archipelago server
 * announces both to everyone, and the bridge relays the announcement to the feed.
 */
final class RecordSessionFeedEventReleaseTest extends TestCase
{
    /** @var array<string, SessionSlot> */
    private array $slots = [];
    private int $flushes = 0;

    protected function setUp(): void
    {
        $this->slots = [
            'Lone' => SessionSlot::create('slot-1', 'session-1', 'reg-1', 'game-1', 'Lone', 1),
            'Pierre' => SessionSlot::create('slot-2', 'session-1', 'reg-2', 'game-1', 'Pierre', 2),
        ];
    }

    public function testAReleaseNamedByTheBridgeExcludesThatSlotOnly(): void
    {
        $this->record(['type' => 'release', 'text' => 'Lone (Team #1) has released all remaining items from their world.', 'sender' => ['slot' => 1, 'name' => 'Lone', 'game' => 'A Link to the Past']]);

        self::assertTrue($this->slots['Lone']->isWasReleased());
        self::assertFalse($this->slots['Pierre']->isWasReleased(), 'the rest of the run keeps counting');
        self::assertSame(1, $this->flushes);
    }

    public function testACollectFromAnOlderBridgeIsReadFromItsText(): void
    {
        $this->record(['type' => 'collect', 'text' => 'Pierre (Team #1) has collected their items from other worlds.']);

        self::assertTrue($this->slots['Pierre']->isWasReleased());
    }

    public function testAForfeitCountsAsARelease(): void
    {
        $this->record(['type' => 'forfeit', 'text' => 'Lone (Team #1) has forfeited.', 'sender' => ['name' => 'Lone']]);

        self::assertTrue($this->slots['Lone']->isWasReleased());
    }

    public function testTheReleaseThatFollowsAGoalRemovesNothing(): void
    {
        // The server releases a finished player automatically, right after their goal.
        $this->slots['Lone']->recordGoal(new \DateTimeImmutable('2026-09-28 20:00:00'));

        $this->record(['type' => 'release', 'text' => 'Lone (Team #1) has released all remaining items from their world.', 'sender' => ['name' => 'Lone']]);

        self::assertFalse($this->slots['Lone']->isWasReleased());
    }

    public function testAnUnknownPlayerChangesNothing(): void
    {
        $this->record(['type' => 'release', 'text' => 'Ghost (Team #1) has released all remaining items from their world.']);

        self::assertFalse($this->slots['Lone']->isWasReleased());
        self::assertFalse($this->slots['Pierre']->isWasReleased());
        self::assertSame(0, $this->flushes);
    }

    public function testOtherEventsLeaveTheSlotsAlone(): void
    {
        $this->record(['type' => 'item-received', 'text' => 'Lone found Master Sword for Pierre', 'sender' => ['name' => 'Lone']]);

        self::assertFalse($this->slots['Lone']->isWasReleased());
        self::assertSame(0, $this->flushes);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function record(array $event): void
    {
        $slots = self::createStub(SessionSlotRepositoryInterface::class);
        $slots->method('findBySessionAndSlotName')->willReturnCallback(
            fn (string $sessionId, string $name): ?SessionSlot => 'session-1' === $sessionId ? ($this->slots[$name] ?? null) : null,
        );
        $slots->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        new RecordSessionFeedEvent(self::createStub(SessionFeedEventRepositoryInterface::class), $slots, new MockClock('2026-09-28 21:00:00'))
            ->record('session-1', $event);
    }
}
