<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Application\Handler\ReconcileApworldIncidentsHandler;
use App\GameSelection\Application\Message\NotifyApworldIncidentAdminsJob;
use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\ReconcileApworldIncidentsMessage;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class ReconcileApworldIncidentsHandlerTest extends TestCase
{
    private InMemoryApworldIncidentRepository $incidents;

    protected function setUp(): void
    {
        $this->incidents = new InMemoryApworldIncidentRepository();
    }

    public function testDispatchesOneAdminAndOneStaffJobPerOpenedIncidentAfterFlush(): void
    {
        $bus = $this->reconcileOnce([new ServedApworld('game-1', 'hash-1')], ['hash-1' => $this->verdict('failed')]);

        $incidentId = $this->incidents->all()[0]->getId();
        self::assertEquals([
            new NotifyApworldIncidentAdminsJob($incidentId),
            new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Opened),
        ], $bus->messages());
        foreach ($bus->dispatched as $dispatch) {
            self::assertSame(1, $dispatch['flushesBefore'], 'alerts leave only once the transition is committed');
        }
    }

    public function testRecurrenceDispatchesNothing(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed')]);

        $bus = $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed')]);

        self::assertSame([], $bus->dispatched);
    }

    public function testAResolutionPostsOnlyToTheStaffChannel(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed')]);

        $bus = $this->reconcileOnce($served, ['hash-1' => $this->verdict('passed')]);

        $incidentId = $this->incidents->all()[0]->getId();
        self::assertEquals(
            [new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Resolved)],
            $bus->messages(),
        );
    }

    public function testAnOverrideIgnorePostsOnlyToTheStaffChannel(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed')]);

        $bus = $this->reconcileOnce($served, ['hash-1' => $this->verdict('failed', overridden: true)]);

        $incidentId = $this->incidents->all()[0]->getId();
        self::assertEquals(
            [new PostApworldIncidentToStaffChannelJob($incidentId, StaffAlertEvent::Ignored)],
            $bus->messages(),
        );
    }

    public function testRunnerUnavailableDispatchesNothing(): void
    {
        $bus = $this->reconcileOnce([new ServedApworld('game-1', 'hash-1')], []);

        self::assertSame([], $bus->dispatched);
    }

    /**
     * @param list<ServedApworld>                                                                                    $served
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}> $verdicts
     */
    private function reconcileOnce(array $served, array $verdicts): SpyMessageBus
    {
        $servedQuery = self::createStub(ServedApworldsQueryInterface::class);
        $servedQuery->method('servedApworlds')->willReturn($served);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);
        $clock = new MockClock('2026-09-24 10:00:00+00:00');
        $bus = new SpyMessageBus($this->incidents);

        $handler = new ReconcileApworldIncidentsHandler(
            new ReconcileApworldIncidents($servedQuery, $runner, $this->incidents, new RecordApworldIncident($this->incidents, $clock), $clock),
            new ApworldIncidentAlertDispatcher($bus),
            new NullLogger(),
        );
        $this->incidents->flushes = 0;

        $handler(new ReconcileApworldIncidentsMessage());

        return $bus;
    }

    /**
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}
     */
    private function verdict(string $status, bool $overridden = false): array
    {
        return [
            'status' => $status,
            'error' => 'failed' === $status ? 'boom' : '',
            'checkedAt' => '2026-09-24T09:55:00Z',
            'overridden' => $overridden,
            'blocks' => 'failed' === $status && !$overridden,
        ];
    }
}
