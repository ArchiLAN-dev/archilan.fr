<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Command\RecordSlotPresence;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotPlaySpan;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotPlaySpanRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Story 32.26: each heartbeat extends the latest span of every slot it names, or opens a new one after a gap.
 */
final class RecordSlotPresenceTest extends TestCase
{
    private MockClock $clock;

    private InMemorySlotPlaySpans $spans;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-27T20:00:00+00:00');
        $this->spans = new InMemorySlotPlaySpans();
    }

    public function testHeartbeatsCloseTogetherGrowOneSpan(): void
    {
        $presence = $this->presence(Session::createRunning('s1', 'e1', 'host', 38281, null, null, $this->clock->now()));

        $presence->record('s1', ['Zoe']);
        $this->clock->sleep(30);
        $presence->record('s1', ['Zoe']);
        $this->clock->sleep(SlotPlaySpan::MAX_GAP_SECONDS);
        $presence->record('s1', ['Zoe']);

        self::assertCount(1, $this->spans->all);
        self::assertSame(30 + SlotPlaySpan::MAX_GAP_SECONDS, $this->spans->all[0]->durationSeconds());
    }

    public function testALongerGapOpensANewSpan(): void
    {
        $presence = $this->presence(Session::createRunning('s1', 'e1', 'host', 38281, null, null, $this->clock->now()));

        $presence->record('s1', ['Zoe']);
        $this->clock->sleep(60);
        $presence->record('s1', ['Zoe']);
        $this->clock->sleep(SlotPlaySpan::MAX_GAP_SECONDS + 1);
        $presence->record('s1', ['Zoe']);

        self::assertCount(2, $this->spans->all);
        self::assertSame(60, $this->spans->all[0]->durationSeconds());
        self::assertSame(0, $this->spans->all[1]->durationSeconds(), 'a single sighting counts for nothing yet');
    }

    public function testEachSlotHasItsOwnSpanAndUnknownNamesAreDropped(): void
    {
        $presence = $this->presence(Session::createRunning('s1', 'e1', 'host', 38281, null, null, $this->clock->now()));

        $presence->record('s1', ['Zoe', 'Max', 'Zoe', 'Stranger']);

        self::assertSame(['Zoe', 'Max'], array_map(static fn (SlotPlaySpan $span): string => $span->getSlotName(), $this->spans->all));
    }

    public function testNothingIsCountedOutsideARunningSession(): void
    {
        $presence = $this->presence(Session::create('s1', 'e1', $this->clock->now()));

        $presence->record('s1', ['Zoe']);

        self::assertSame([], $this->spans->all);
    }

    public function testAnEmptyHeartbeatTouchesNothing(): void
    {
        $presence = $this->presence(Session::createRunning('s1', 'e1', 'host', 38281, null, null, $this->clock->now()));

        $presence->record('s1', []);

        self::assertSame([], $this->spans->all);
        self::assertSame(0, $this->spans->flushes);
    }

    private function presence(Session $session): RecordSlotPresence
    {
        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);
        $slots = self::createStub(SessionSlotRepositoryInterface::class);
        $slots->method('findBySessionId')->willReturn([
            SessionSlot::create('slot-1', 's1', 'reg-1', 'game-1', 'Zoe', 1),
            SessionSlot::create('slot-2', 's1', 'reg-2', 'game-1', 'Max', 2),
        ]);

        return new RecordSlotPresence($sessions, $slots, $this->spans, $this->clock);
    }
}

final class InMemorySlotPlaySpans implements SlotPlaySpanRepositoryInterface
{
    /** @var list<SlotPlaySpan> */
    public array $all = [];

    public int $flushes = 0;

    public function findLatest(string $sessionId, string $slotName): ?SlotPlaySpan
    {
        $latest = null;
        foreach ($this->all as $span) {
            if ($span->getSessionId() === $sessionId && $span->getSlotName() === $slotName
                && (null === $latest || $span->getLastSeenAt() >= $latest->getLastSeenAt())) {
                $latest = $span;
            }
        }

        return $latest;
    }

    public function playedSecondsBySlotName(string $sessionId): array
    {
        return [];
    }

    public function add(SlotPlaySpan $span): void
    {
        $this->all[] = $span;
    }

    public function flush(): void
    {
        ++$this->flushes;
    }
}
