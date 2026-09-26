<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\ApworldIncidentRecordOutcome;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RecordApworldIncidentTest extends TestCase
{
    private InMemoryApworldIncidentRepository $incidents;
    private MockClock $clock;
    private RecordApworldIncident $record;

    protected function setUp(): void
    {
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->clock = new MockClock('2026-09-24 10:00:00+00:00');
        $this->record = new RecordApworldIncident($this->incidents, $this->clock);
    }

    public function testOpensWhenNoActiveIncident(): void
    {
        $recording = $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'FillError: boom');

        self::assertSame(ApworldIncidentRecordOutcome::Opened, $recording->outcome);
        self::assertNotNull($recording->incidentId);
        $incident = $this->incidents->findById($recording->incidentId);
        self::assertInstanceOf(ApworldIncident::class, $incident);
        self::assertSame(ApworldIncidentStatus::Open, $incident->getStatus());
        self::assertSame('FillError: boom', $incident->getError());
        self::assertEquals($this->clock->now(), $incident->getOpenedAt());
    }

    public function testCountsARecurrenceOnTheActiveIncident(): void
    {
        $first = $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'first');
        $this->clock->sleep(300);

        $second = $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'second');

        self::assertSame(ApworldIncidentRecordOutcome::Recurred, $second->outcome);
        self::assertSame($first->incidentId, $second->incidentId);
        self::assertCount(1, $this->incidents->all());
        $incident = $this->incidents->all()[0];
        self::assertSame(2, $incident->getOccurrences());
        self::assertSame('second', $incident->getError());
    }

    public function testCountsARecurrenceOnAnAcknowledgedIncident(): void
    {
        $first = $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'first');
        $this->incidents->findById((string) $first->incidentId)?->acknowledge('admin-1', $this->clock->now());

        $second = $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'second');

        self::assertSame(ApworldIncidentRecordOutcome::Recurred, $second->outcome);
        self::assertCount(1, $this->incidents->all());
    }

    public function testDoesNotReopenAnIgnoredIncidentForTheSameHash(): void
    {
        $first = $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'first');
        $this->incidents->findById((string) $first->incidentId)?->ignore($this->clock->now(), 'admin-1');

        $again = $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'again');

        self::assertSame(ApworldIncidentRecordOutcome::Suppressed, $again->outcome);
        self::assertNull($again->incidentId);
        self::assertCount(1, $this->incidents->all());
    }

    public function testOpensANewIncidentAfterAResolvedOne(): void
    {
        $first = $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'first');
        $this->incidents->findById((string) $first->incidentId)?->resolve($this->clock->now(), null);
        $this->clock->sleep(60);

        $relapse = $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'relapse');

        self::assertSame(ApworldIncidentRecordOutcome::Opened, $relapse->outcome);
        self::assertNotSame($first->incidentId, $relapse->incidentId);
        self::assertCount(2, $this->incidents->all());
    }

    public function testAnotherHashOfTheSameGameIsASeparateIncident(): void
    {
        $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'old');

        $other = $this->record->record('game-1', 'hash-2', ApworldIncidentType::PreflightFailed, 'new');

        self::assertSame(ApworldIncidentRecordOutcome::Opened, $other->outcome);
        self::assertCount(2, $this->incidents->all());
    }

    public function testNeverFlushesItself(): void
    {
        $this->record->record('game-1', 'hash-1', ApworldIncidentType::PreflightFailed, 'boom');

        self::assertSame(0, $this->incidents->flushes);
    }
}
