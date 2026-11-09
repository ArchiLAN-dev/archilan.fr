<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\PersonalRuns\Domain\Entity\Run;
use App\Registrations\Application\Query\PresenceTimelineQueryInterface;
use App\Sessions\Application\Support\SlotAbsence;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotPlaySpan;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotPlaySpanRepositoryInterface;
use PHPUnit\Framework\TestCase;

/** Story 52.3: who is absent, the only worlds the Passeur may open. */
final class SlotAbsenceTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2027-06-05T14:00:00+00:00');
    }

    public function testAtAnEventOnlyAPlayerOutsideTheirWindowsAndOfflineIsAbsent(): void
    {
        // Bob is outside his windows and offline; Carol outside hers but playing; Dave declared no window.
        $absence = $this->absence(
            lastSeen: ['Carol' => $this->now->modify('-30 seconds'), 'Dave' => $this->now->modify('-1 day')],
            outsideWindows: ['reg-bob' => true, 'reg-carol' => true, 'reg-dave' => false],
        );

        self::assertSame([2], $absence->absentSlots($this->session(), null, $this->now));
    }

    public function testInAPrivateRunAPlayerOfflineForTwoHoursIsAbsent(): void
    {
        // Bob last seen 2 h ago, Carol 1 h ago, Dave never - and the run launched 3 h ago.
        $absence = $this->absence(
            lastSeen: ['Bob' => $this->now->modify('-2 hours'), 'Carol' => $this->now->modify('-1 hour'), 'Alice' => $this->now],
            outsideWindows: [],
        );
        $run = Run::create('owner-1', 'Ma run', $this->now->modify('-1 day'));

        self::assertSame([2, 4], $absence->absentSlots($this->session(launchedAt: $this->now->modify('-3 hours')), $run, $this->now));
    }

    public function testInAPrivateRunASlotNeverPlayedWaitsForTheDelayFromTheLaunch(): void
    {
        $absence = $this->absence(lastSeen: ['Alice' => $this->now, 'Bob' => $this->now, 'Carol' => $this->now], outsideWindows: []);
        $run = Run::create('owner-1', 'Ma run', $this->now->modify('-1 day'));

        self::assertSame([], $absence->absentSlots($this->session(launchedAt: $this->now->modify('-90 minutes')), $run, $this->now));
    }

    /**
     * Slots 1 Alice (the buyer), 2 Bob, 3 Carol, 4 Dave, and the bridge's observer.
     *
     * @param array<string, \DateTimeImmutable> $lastSeen       by slot name
     * @param array<string, bool>               $outsideWindows by registration id
     */
    private function absence(array $lastSeen, array $outsideWindows): SlotAbsence
    {
        $names = ['Alice', 'Bob', 'Carol', 'Dave'];
        $payload = ['slots' => ['5' => ['slot_name' => 'Bridge']]];
        $sessionSlots = [];
        foreach ($names as $i => $name) {
            $payload['slots'][(string) ($i + 1)] = ['slot_name' => $name];
            $sessionSlots[] = SessionSlot::create('s'.$i, 'session-1', 'reg-'.strtolower($name), 'game-1', $name, 1, 'gs'.$i);
        }

        $snapshots = self::createStub(SessionPlayersSnapshotRepositoryInterface::class);
        $snapshots->method('findBySessionId')->willReturn(new SessionPlayersSnapshot('session-1', $payload, $this->now));
        $slots = self::createStub(SessionSlotRepositoryInterface::class);
        $slots->method('findBySessionId')->willReturn($sessionSlots);
        $spans = self::createStub(SlotPlaySpanRepositoryInterface::class);
        $spans->method('findLatest')->willReturnCallback(
            static fn (string $sessionId, string $slotName): ?SlotPlaySpan => isset($lastSeen[$slotName]) ? SlotPlaySpan::open('span-'.$slotName, $sessionId, $slotName, $lastSeen[$slotName]) : null,
        );
        $presence = self::createStub(PresenceTimelineQueryInterface::class);
        $presence->method('isOutsideWindows')->willReturnCallback(static fn (string $registrationId): bool => $outsideWindows[$registrationId] ?? false);

        return new SlotAbsence($snapshots, $slots, $spans, $presence);
    }

    private function session(?\DateTimeImmutable $launchedAt = null): Session
    {
        $session = Session::create('session-1', 'event-1', $this->now->modify('-2 days'));
        if (null !== $launchedAt) {
            foreach ([Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING] as $status) {
                $session->transition($status, $launchedAt);
            }
            $session->transition(Session::STATUS_RUNNING, $launchedAt, 'ap.example', 38281);
        }

        return $session;
    }
}
