<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Message\NotifySlotUnblockedJob;
use App\Sessions\Application\Service\SlotBlockTracker;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotBlockEpisode;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotBlockEpisodeRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 40.1. Every players push of a private run updates the slots' block episodes; leaving a
 * real block (2 minutes) dispatches one notification job, and nothing else does.
 */
final class SlotBlockTrackerTest extends TestCase
{
    private InMemorySlotBlockEpisodes $episodes;
    private SpySlotBlockBus $bus;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->episodes = new InMemorySlotBlockEpisodes();
        $this->bus = new SpySlotBlockBus();
        $this->clock = new MockClock('2026-09-29T10:00:00+00:00');
    }

    public function testABlockedSlotOpensAnEpisodeAndLeavingItAfterTwoMinutesNotifiesOnce(): void
    {
        $tracker = $this->tracker($this->privateRun());

        $tracker->track('session-1', $this->payload(reachableNow: 0));
        self::assertCount(1, $this->episodes->all);
        self::assertSame([], $this->bus->messages);

        $this->clock->sleep(60);
        $tracker->track('session-1', $this->payload(reachableNow: 0));
        self::assertCount(1, $this->episodes->all, 'a repeated block keeps the same episode');

        $this->clock->sleep(90);
        $tracker->track('session-1', $this->payload(reachableNow: 4));
        self::assertSame([], $this->episodes->all);
        self::assertCount(1, $this->bus->messages);
        $job = $this->bus->messages[0];
        self::assertSame('session-1', $job->sessionId);
        self::assertSame('Alice_HK1', $job->slotName);
        self::assertSame(4, $job->reachableNow);

        $tracker->track('session-1', $this->payload(reachableNow: 4));
        self::assertCount(1, $this->bus->messages, 'a duplicate push does not notify twice');
    }

    public function testAShortBlockIsForgottenWithoutNotification(): void
    {
        $tracker = $this->tracker($this->privateRun());

        $tracker->track('session-1', $this->payload(reachableNow: 0));
        $this->clock->sleep(30);
        $tracker->track('session-1', $this->payload(reachableNow: 2));

        self::assertSame([], $this->episodes->all);
        self::assertSame([], $this->bus->messages);
    }

    public function testTheBridgeRestartingClosesTheEpisodeWithoutNotification(): void
    {
        // Story 40.3: blocked, then the server stops and comes back from its last save - the bridge starts with
        // an unknown state, then finds checks reachable again: that is no way out of a block.
        $tracker = $this->tracker($this->privateRun());

        $tracker->track('session-1', $this->payload(reachableNow: 0));
        $this->clock->sleep(3600);
        $tracker->track('session-1', $this->payload(reachableNow: null));
        self::assertSame([], $this->episodes->all);

        $tracker->track('session-1', $this->payload(reachableNow: 3));
        self::assertSame([], $this->bus->messages);
    }

    public function testABlockBegunBeforeTheLastStopNeverNotifies(): void
    {
        // Story 40.3: even without the bridge's unknown state, a block older than the server's last stop is
        // closed silently; a block begun after it still notifies.
        $session = $this->stoppedSessionAt($this->clock->now()->modify('+10 minutes'));
        $tracker = $this->tracker($this->privateRun(), session: $session);

        $tracker->track('session-1', $this->payload(reachableNow: 0));
        $this->clock->sleep(3600);
        $tracker->track('session-1', $this->payload(reachableNow: 2));
        self::assertSame([], $this->episodes->all);
        self::assertSame([], $this->bus->messages);

        $tracker->track('session-1', $this->payload(reachableNow: 0));
        $this->clock->sleep(300);
        $tracker->track('session-1', $this->payload(reachableNow: 2));
        self::assertCount(1, $this->bus->messages);
    }

    public function testAReleasedSlotClosesItsEpisodeSilently(): void
    {
        $slot = SessionSlot::create('slot-1', 'session-1', 'user-1', 'game-1', 'Alice_HK1', 0, 'game-slot-1');
        $tracker = $this->tracker($this->privateRun(), [$slot]);

        $tracker->track('session-1', $this->payload(reachableNow: 0));
        $slot->markAsReleased();
        $this->clock->sleep(600);
        $tracker->track('session-1', $this->payload(reachableNow: 0));

        self::assertSame([], $this->episodes->all);
        self::assertSame([], $this->bus->messages);
    }

    public function testTheBridgeObserverSlotIsIgnored(): void
    {
        $tracker = $this->tracker($this->privateRun());

        $tracker->track('session-1', ['slots' => ['1' => $this->slot('Bridge', 0)]]);

        self::assertSame([], $this->episodes->all);
    }

    public function testAnEventSessionTracksNothing(): void
    {
        $tracker = $this->tracker(null);

        $tracker->track('session-1', $this->payload(reachableNow: 0));

        self::assertSame([], $this->episodes->all);
    }

    public function testAnImportedSeedTracksNothing(): void
    {
        $run = $this->privateRun();
        $run->importSeed('imports/seed.zip', [], new \DateTimeImmutable('2026-09-29T09:00:00+00:00'));
        $tracker = $this->tracker($run);

        $tracker->track('session-1', $this->payload(reachableNow: 0));

        self::assertSame([], $this->episodes->all);
    }

    public function testAMalformedPayloadIsIgnored(): void
    {
        $tracker = $this->tracker($this->privateRun());

        $tracker->track('session-1', ['slots' => 'nope']);
        $tracker->track('session-1', ['slots' => ['1' => 'nope', '2' => ['checks_done' => 1]]]);

        self::assertSame([], $this->episodes->all);
    }

    private function privateRun(): Run
    {
        return Run::create('owner-1', 'Ma run', new \DateTimeImmutable('2026-09-29T09:00:00+00:00'));
    }

    /**
     * @param list<SessionSlot> $slots
     */
    private function tracker(?Run $run, array $slots = [], ?Session $session = null): SlotBlockTracker
    {
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findBySessionId')->willReturn($run);

        $slotRepository = self::createStub(SessionSlotRepositoryInterface::class);
        $slotRepository->method('findBySessionId')->willReturn($slots);

        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        return new SlotBlockTracker($runs, $slotRepository, $sessions, $this->episodes, $this->bus, $this->clock);
    }

    /** A session that ran, then stopped at that instant. */
    private function stoppedSessionAt(\DateTimeImmutable $at): Session
    {
        $session = Session::create('session-1', 'run-1', $at->modify('-1 day'));
        foreach ([Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING] as $status) {
            $session->transition($status, $at->modify('-1 hour'));
        }
        $session->transition(Session::STATUS_RUNNING, $at->modify('-1 hour'), 'ap.example', 38281);
        $session->transition(Session::STATUS_STOPPED, $at);

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?int $reachableNow): array
    {
        return ['slots' => ['1' => $this->slot('Alice_HK1', $reachableNow)]];
    }

    /**
     * @return array<string, mixed>
     */
    private function slot(string $name, ?int $reachableNow): array
    {
        return [
            'slot_name' => $name,
            'checks_done' => 12,
            'checks_total' => 47,
            'items_received' => 8,
            'client_status' => 20,
            'goal_reached_at' => null,
            'reachable_now' => $reachableNow,
        ];
    }
}

final class InMemorySlotBlockEpisodes implements SlotBlockEpisodeRepositoryInterface
{
    /** @var array<string, SlotBlockEpisode> */
    public array $all = [];

    public function findBySessionId(string $sessionId): array
    {
        return array_values(array_filter($this->all, static fn (SlotBlockEpisode $episode): bool => $episode->getSessionId() === $sessionId));
    }

    public function add(SlotBlockEpisode $episode): void
    {
        $this->all[$episode->getSessionId().'#'.$episode->getSlotIndex()] = $episode;
    }

    public function remove(SlotBlockEpisode $episode): void
    {
        unset($this->all[$episode->getSessionId().'#'.$episode->getSlotIndex()]);
    }

    public function flush(): void
    {
    }
}

final class SpySlotBlockBus implements MessageBusInterface
{
    /** @var list<NotifySlotUnblockedJob> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if ($message instanceof NotifySlotUnblockedJob) {
            $this->messages[] = $message;
        }

        return new Envelope($message, $stamps);
    }
}
