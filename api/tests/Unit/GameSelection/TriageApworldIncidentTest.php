<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\ApworldIncidentTriageOutcome;
use App\GameSelection\Application\Command\TriageApworldIncident;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class TriageApworldIncidentTest extends TestCase
{
    private InMemoryApworldIncidentRepository $incidents;
    private SpyMessageBus $bus;
    private MockClock $clock;
    private TriageApworldIncident $triage;

    protected function setUp(): void
    {
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->incidents->save(ApworldIncident::open(
            'incident-1',
            'game-1',
            'hash-1',
            ApworldIncidentType::PreflightFailed,
            'boom',
            new \DateTimeImmutable('2026-09-24 10:00:00+00:00'),
        ));
        $this->bus = new SpyMessageBus($this->incidents);
        $this->clock = new MockClock('2026-09-25 09:00:00+00:00');
        $this->triage = new TriageApworldIncident($this->incidents, $this->bus, $this->clock);
    }

    public function testAcknowledgeAppliesTheTransitionFlushesThenAlerts(): void
    {
        $outcome = $this->triage->acknowledge('incident-1', 'admin-1');

        self::assertSame(ApworldIncidentTriageOutcome::Applied, $outcome);
        $incident = $this->incidents->findById('incident-1');
        self::assertSame(ApworldIncidentStatus::Acknowledged, $incident?->getStatus());
        self::assertSame('admin-1', $incident->getAcknowledgedBy());
        self::assertEquals($this->clock->now(), $incident->getAcknowledgedAt());
        self::assertEquals(
            [new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Acknowledged)],
            $this->bus->messages(),
        );
        self::assertSame(1, $this->bus->dispatched[0]['flushesBefore'], 'the alert leaves after the commit');
    }

    public function testResolveRecordsTheAdminAndAlerts(): void
    {
        $outcome = $this->triage->resolve('incident-1', 'admin-1');

        self::assertSame(ApworldIncidentTriageOutcome::Applied, $outcome);
        $incident = $this->incidents->findById('incident-1');
        self::assertSame(ApworldIncidentStatus::Resolved, $incident?->getStatus());
        self::assertSame('admin-1', $incident->getClosedBy());
        self::assertEquals(
            [new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Resolved)],
            $this->bus->messages(),
        );
    }

    public function testIgnoreRecordsTheAdminAndAlerts(): void
    {
        $outcome = $this->triage->ignore('incident-1', 'admin-1');

        self::assertSame(ApworldIncidentTriageOutcome::Applied, $outcome);
        $incident = $this->incidents->findById('incident-1');
        self::assertSame(ApworldIncidentStatus::Ignored, $incident?->getStatus());
        self::assertSame('admin-1', $incident->getClosedBy());
        self::assertEquals(
            [new PostApworldIncidentToStaffChannelJob('incident-1', StaffAlertEvent::Ignored)],
            $this->bus->messages(),
        );
    }

    public function testForbiddenTransitionIsReportedWithoutCommitOrAlert(): void
    {
        $this->triage->resolve('incident-1', 'admin-1');
        $this->incidents->flushes = 0;
        $this->bus->dispatched = [];

        $outcome = $this->triage->acknowledge('incident-1', 'admin-2');

        self::assertSame(ApworldIncidentTriageOutcome::Forbidden, $outcome);
        self::assertSame(0, $this->incidents->flushes);
        self::assertSame([], $this->bus->dispatched);
    }

    public function testUnknownIncidentIsReportedAsNotFound(): void
    {
        self::assertSame(ApworldIncidentTriageOutcome::NotFound, $this->triage->ignore('unknown', 'admin-1'));
        self::assertSame([], $this->bus->dispatched);
    }
}
