<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Exception\ApworldIncidentTransitionException;
use PHPUnit\Framework\TestCase;

final class ApworldIncidentTest extends TestCase
{
    private const string OPENED_AT = '2026-09-24 10:00:00+00:00';

    public function testOpenStartsAnActiveIncidentWithOneOccurrence(): void
    {
        $incident = $this->openIncident();

        self::assertSame('incident-1', $incident->getId());
        self::assertSame('game-1', $incident->getGameId());
        self::assertSame('hash-1', $incident->getApworldHash());
        self::assertSame(ApworldIncidentType::PreflightFailed, $incident->getType());
        self::assertSame(ApworldIncidentStatus::Open, $incident->getStatus());
        self::assertTrue($incident->isActive());
        self::assertSame('FillError: boom', $incident->getError());
        self::assertSame(1, $incident->getOccurrences());
        self::assertEquals(new \DateTimeImmutable(self::OPENED_AT), $incident->getOpenedAt());
        self::assertEquals(new \DateTimeImmutable(self::OPENED_AT), $incident->getLastSeenAt());
        self::assertNull($incident->getAcknowledgedBy());
        self::assertNull($incident->getClosedAt());
    }

    public function testRecordRecurrenceUpdatesErrorLastSeenAndCount(): void
    {
        $incident = $this->openIncident();
        $later = new \DateTimeImmutable('2026-09-24 10:05:00+00:00');

        $incident->recordRecurrence('FillError: boom again', $later);

        self::assertSame(2, $incident->getOccurrences());
        self::assertSame('FillError: boom again', $incident->getError());
        self::assertEquals($later, $incident->getLastSeenAt());
        self::assertEquals(new \DateTimeImmutable(self::OPENED_AT), $incident->getOpenedAt());
        self::assertSame(ApworldIncidentStatus::Open, $incident->getStatus());
    }

    public function testAcknowledgeRecordsWhoAndWhenAndStaysActive(): void
    {
        $incident = $this->openIncident();
        $at = new \DateTimeImmutable('2026-09-24 11:00:00+00:00');

        $incident->acknowledge('admin-1', $at);

        self::assertSame(ApworldIncidentStatus::Acknowledged, $incident->getStatus());
        self::assertTrue($incident->isActive());
        self::assertSame('admin-1', $incident->getAcknowledgedBy());
        self::assertEquals($at, $incident->getAcknowledgedAt());
    }

    public function testAcknowledgingAgainHandsTheIncidentOverToAnotherAdmin(): void
    {
        $incident = $this->openIncident();
        $incident->acknowledge('admin-1', new \DateTimeImmutable('2026-09-24 11:00:00+00:00'));
        $takeover = new \DateTimeImmutable('2026-09-24 12:00:00+00:00');

        $incident->acknowledge('admin-2', $takeover);

        self::assertSame('admin-2', $incident->getAcknowledgedBy());
        self::assertEquals($takeover, $incident->getAcknowledgedAt());
    }

    public function testResolveClosesAndRecordsAutomaticResolutionWithoutUser(): void
    {
        $incident = $this->openIncident();
        $at = new \DateTimeImmutable('2026-09-24 12:00:00+00:00');

        $incident->resolve($at, null);

        self::assertSame(ApworldIncidentStatus::Resolved, $incident->getStatus());
        self::assertFalse($incident->isActive());
        self::assertEquals($at, $incident->getClosedAt());
        self::assertNull($incident->getClosedBy());
        self::assertTrue($incident->wasClosedAutomatically());
    }

    public function testResolveByAdminRecordsManualResolution(): void
    {
        $incident = $this->openIncident();

        $incident->resolve(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), 'admin-1');

        self::assertSame(ApworldIncidentStatus::Resolved, $incident->getStatus());
        self::assertSame('admin-1', $incident->getClosedBy());
        self::assertFalse($incident->wasClosedAutomatically());
    }

    public function testAnAcknowledgedIncidentCanStillBeResolvedAutomatically(): void
    {
        $incident = $this->openIncident();
        $incident->acknowledge('admin-1', new \DateTimeImmutable('2026-09-24 11:00:00+00:00'));

        $incident->resolve(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), null);

        self::assertSame(ApworldIncidentStatus::Resolved, $incident->getStatus());
        self::assertSame('admin-1', $incident->getAcknowledgedBy());
    }

    public function testIgnoreClosesAsIgnored(): void
    {
        $incident = $this->openIncident();
        $at = new \DateTimeImmutable('2026-09-24 12:00:00+00:00');

        $incident->ignore($at, 'admin-1');

        self::assertSame(ApworldIncidentStatus::Ignored, $incident->getStatus());
        self::assertFalse($incident->isActive());
        self::assertEquals($at, $incident->getClosedAt());
        self::assertSame('admin-1', $incident->getClosedBy());
    }

    public function testResolvingAClosedIncidentThrows(): void
    {
        $incident = $this->openIncident();
        $incident->resolve(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), null);

        $this->expectException(ApworldIncidentTransitionException::class);

        $incident->resolve(new \DateTimeImmutable('2026-09-24 13:00:00+00:00'), 'admin-1');
    }

    public function testAcknowledgingAnIgnoredIncidentThrows(): void
    {
        $incident = $this->openIncident();
        $incident->ignore(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), 'admin-1');

        $this->expectException(ApworldIncidentTransitionException::class);

        $incident->acknowledge('admin-2', new \DateTimeImmutable('2026-09-24 13:00:00+00:00'));
    }

    public function testIgnoringAResolvedIncidentThrows(): void
    {
        $incident = $this->openIncident();
        $incident->resolve(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), null);

        $this->expectException(ApworldIncidentTransitionException::class);

        $incident->ignore(new \DateTimeImmutable('2026-09-24 13:00:00+00:00'), 'admin-1');
    }

    public function testRecordingARecurrenceOnAClosedIncidentThrows(): void
    {
        $incident = $this->openIncident();
        $incident->resolve(new \DateTimeImmutable('2026-09-24 12:00:00+00:00'), null);

        $this->expectException(ApworldIncidentTransitionException::class);

        $incident->recordRecurrence('again', new \DateTimeImmutable('2026-09-24 13:00:00+00:00'));
    }

    private function openIncident(): ApworldIncident
    {
        return ApworldIncident::open(
            'incident-1',
            'game-1',
            'hash-1',
            ApworldIncidentType::PreflightFailed,
            'FillError: boom',
            new \DateTimeImmutable(self::OPENED_AT),
        );
    }
}
