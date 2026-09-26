<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Command\ReconcileApworldIncidentsResult;
use App\GameSelection\Application\Command\RecordApworldIncident;
use App\GameSelection\Application\Query\ServedApworld;
use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReconcileApworldIncidentsTest extends TestCase
{
    private InMemoryApworldIncidentRepository $incidents;
    private InMemoryApworldHealthRepository $health;
    private MockClock $clock;
    /** @var list<string> */
    private array $retriedHashes = [];

    protected function setUp(): void
    {
        $this->incidents = new InMemoryApworldIncidentRepository();
        $this->health = new InMemoryApworldHealthRepository();
        $this->clock = new MockClock('2026-09-24 10:00:00+00:00');
    }

    public function testFirstFailureOfAHashThatPassedRetriesWithoutIncident(): void
    {
        // Story 38.9: a seed can be unlucky. A hash that passed is retested at once, not reported yet.
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcile($served, ['hash-1' => $this->verdict('passed', checkedAt: '2026-09-20T05:00:00Z', image: 'archipelago:0.16.0', imageId: 'sha256:old')]);

        $result = $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'FillError', checkedAt: '2026-09-27T05:00:00Z', image: 'archipelago:0.16.1', imageId: 'sha256:new')]);

        self::assertSame([], $result->openedIncidentIds);
        self::assertSame([], $this->incidents->all());
        self::assertSame(['hash-1'], $this->retriedHashes);
    }

    public function testSecondConsecutiveFailureOpensAnImageRegressionWhenTheImageChanged(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcile($served, ['hash-1' => $this->verdict('passed', checkedAt: '2026-09-20T05:00:00Z', image: 'archipelago:0.16.0', imageId: 'sha256:old')]);
        $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'FillError', checkedAt: '2026-09-27T05:00:00Z', image: 'archipelago:0.16.1', imageId: 'sha256:new')]);

        $result = $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'FillError: again', checkedAt: '2026-09-27T05:10:00Z', image: 'archipelago:0.16.1', imageId: 'sha256:new')]);

        self::assertCount(1, $result->openedIncidentIds);
        $incident = $this->incidents->all()[0];
        self::assertSame(ApworldIncidentType::ImageRegression, $incident->getType());
        self::assertStringContainsString('archipelago:0.16.0', $incident->getError());
        self::assertStringContainsString('archipelago:0.16.1', $incident->getError());
        self::assertStringContainsString('FillError: again', $incident->getError());
        self::assertSame(['hash-1'], $this->retriedHashes, 'retried once, not twice');
    }

    public function testSecondConsecutiveFailureOnTheSameImageOpensAPreflightFailed(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcile($served, ['hash-1' => $this->verdict('passed', checkedAt: '2026-09-20T05:00:00Z', image: 'archipelago:0.16.1', imageId: 'sha256:same')]);
        $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom', checkedAt: '2026-09-27T05:00:00Z', image: 'archipelago:0.16.1', imageId: 'sha256:same')]);

        $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom', checkedAt: '2026-09-27T05:10:00Z', image: 'archipelago:0.16.1', imageId: 'sha256:same')]);

        self::assertSame(ApworldIncidentType::PreflightFailed, $this->incidents->all()[0]->getType());
    }

    public function testFirstFailureOfAHashThatNeverPassedOpensAnIncidentImmediately(): void
    {
        $result = $this->reconcile([new ServedApworld('game-1', 'hash-1')], ['hash-1' => $this->verdict('failed', 'boom')]);

        self::assertCount(1, $result->openedIncidentIds);
        self::assertSame([], $this->retriedHashes);
    }

    public function testASuccessResolvesAnImageRegression(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcile($served, ['hash-1' => $this->verdict('passed', checkedAt: '2026-09-20T05:00:00Z', image: 'archipelago:0.16.0', imageId: 'sha256:old')]);
        $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom', checkedAt: '2026-09-27T05:00:00Z', image: 'archipelago:0.16.1', imageId: 'sha256:new')]);
        $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom', checkedAt: '2026-09-27T05:10:00Z', image: 'archipelago:0.16.1', imageId: 'sha256:new')]);

        $result = $this->reconcile($served, ['hash-1' => $this->verdict('passed', checkedAt: '2026-09-28T05:00:00Z', image: 'archipelago:0.16.1', imageId: 'sha256:new')]);

        self::assertCount(1, $result->resolvedIncidentIds);
    }

    public function testFailedVerdictOnServedHashOpensAnIncident(): void
    {
        $result = $this->reconcile(
            [new ServedApworld('game-1', 'hash-1')],
            ['hash-1' => $this->verdict('failed', 'Fill.FillError: Could not access required locations')],
        );

        self::assertCount(1, $result->openedIncidentIds);
        $incident = $this->incidents->findById($result->openedIncidentIds[0]);
        self::assertInstanceOf(ApworldIncident::class, $incident);
        self::assertSame('game-1', $incident->getGameId());
        self::assertSame('hash-1', $incident->getApworldHash());
        self::assertSame(ApworldIncidentType::PreflightFailed, $incident->getType());
        self::assertSame('Fill.FillError: Could not access required locations', $incident->getError());
        self::assertSame(1, $this->incidents->flushes);
    }

    public function testANewFailedVerdictIsARecurrenceAndOpensNothing(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom')]);

        $second = $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom again', checkedAt: '2026-09-25T09:55:00Z')]);

        self::assertSame([], $second->openedIncidentIds);
        self::assertCount(1, $this->incidents->all());
        self::assertSame(2, $this->incidents->all()[0]->getOccurrences());
    }

    public function testTheSameVerdictReadAgainIsNotARecurrence(): void
    {
        // The verdict is computed once, then read every five minutes: reading it is not seeing it fail.
        $served = [new ServedApworld('game-1', 'hash-1')];
        $verdicts = ['hash-1' => $this->verdict('failed', 'boom')];
        $this->reconcile($served, $verdicts);
        $firstSeenAt = $this->clock->now();
        $this->clock->sleep(300);

        $this->reconcile($served, $verdicts);

        self::assertSame(1, $this->incidents->all()[0]->getOccurrences());
        self::assertEquals($firstSeenAt, $this->incidents->all()[0]->getLastSeenAt());
    }

    public function testAnIncidentResolvedByHandStaysClosedUntilANewVerdict(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $first = $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom')]);
        $this->incidents->findById($first->openedIncidentIds[0])?->resolve($this->clock->now(), 'admin-1');

        $sameVerdict = $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom')]);
        $newVerdict = $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom', checkedAt: '2026-09-25T09:55:00Z')]);

        self::assertSame([], $sameVerdict->openedIncidentIds, 'the admin closed it on this very verdict');
        self::assertCount(1, $newVerdict->openedIncidentIds, 'a new test failed again: that is a relapse');
    }

    public function testAPassAlreadyRunningChangesNothing(): void
    {
        $result = $this->reconcile([new ServedApworld('game-1', 'hash-1')], ['hash-1' => $this->verdict('failed', 'boom')], lockHeld: true);

        self::assertTrue($result->alreadyRunning);
        self::assertSame([], $this->incidents->all());
    }

    public function testPassedVerdictResolvesTheActiveIncident(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $opened = $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom')]);

        $result = $this->reconcile($served, ['hash-1' => $this->verdict('passed')]);

        self::assertCount(1, $opened->openedIncidentIds);
        self::assertSame($opened->openedIncidentIds, $result->resolvedIncidentIds);
        $incident = $this->incidents->findById($opened->openedIncidentIds[0]);
        self::assertSame(ApworldIncidentStatus::Resolved, $incident?->getStatus());
        self::assertTrue($incident->wasClosedAutomatically());
    }

    public function testIncidentOnAHashNoLongerServedIsResolved(): void
    {
        $opened = $this->reconcile(
            [new ServedApworld('game-1', 'old-hash')],
            ['old-hash' => $this->verdict('failed', 'boom')],
        );

        // A new apworld was imported: the game now serves another hash, still being tested.
        $result = $this->reconcile(
            [new ServedApworld('game-1', 'new-hash')],
            ['old-hash' => $this->verdict('failed', 'boom'), 'new-hash' => $this->verdict('pending')],
        );

        self::assertCount(1, $opened->openedIncidentIds);
        self::assertSame($opened->openedIncidentIds, $result->resolvedIncidentIds);
        self::assertSame([], $this->incidents->findAllActive());
    }

    public function testIncidentOfAGameThatNoLongerServesAnyApworldIsResolved(): void
    {
        $opened = $this->reconcile(
            [new ServedApworld('game-1', 'hash-1')],
            ['hash-1' => $this->verdict('failed', 'boom')],
        );

        $result = $this->reconcile([], ['hash-1' => $this->verdict('failed', 'boom')]);

        self::assertCount(1, $opened->openedIncidentIds);
        self::assertSame($opened->openedIncidentIds, $result->resolvedIncidentIds);
    }

    public function testOverriddenVerdictOpensNothing(): void
    {
        $result = $this->reconcile(
            [new ServedApworld('game-1', 'hash-1')],
            ['hash-1' => $this->verdict('failed', 'boom', overridden: true)],
        );

        self::assertSame([], $result->openedIncidentIds);
        self::assertSame([], $this->incidents->all());
    }

    public function testOverridingAVerdictIgnoresTheActiveIncident(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $opened = $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom')]);

        $result = $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom', overridden: true)]);

        self::assertCount(1, $opened->openedIncidentIds);
        self::assertSame($opened->openedIncidentIds, $result->ignoredIncidentIds);
        $incident = $this->incidents->findById($opened->openedIncidentIds[0]);
        self::assertSame(ApworldIncidentStatus::Ignored, $incident?->getStatus());
        self::assertNull($incident->getClosedBy());
    }

    public function testRunnerUnavailableChangesNothing(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom')]);

        // The runner answers nothing: an unreachable orchestrator must never "heal" a broken apworld,
        // not even the incident whose hash it can no longer see.
        $result = $this->reconcile([new ServedApworld('game-1', 'new-hash')], []);

        self::assertFalse($result->runnerAvailable);
        self::assertSame([], $result->resolvedIncidentIds);
        self::assertCount(1, $this->incidents->findAllActive());
        self::assertSame(1, $this->incidents->flushes);
    }

    public function testPendingSkippedOrMissingVerdictChangesNothing(): void
    {
        $served = [
            new ServedApworld('game-pending', 'hash-pending'),
            new ServedApworld('game-skipped', 'hash-skipped'),
            new ServedApworld('game-unchecked', 'hash-unchecked'),
            new ServedApworld('game-missing', 'hash-missing'),
        ];
        $verdicts = [
            'hash-pending' => $this->verdict('pending'),
            'hash-skipped' => $this->verdict('skipped'),
            'hash-unchecked' => $this->verdict(''),
        ];

        $result = $this->reconcile($served, $verdicts);

        self::assertTrue($result->runnerAvailable);
        self::assertSame([], $result->openedIncidentIds);
        self::assertSame([], $this->incidents->all());
    }

    public function testPendingVerdictKeepsTheActiveIncidentOpen(): void
    {
        $served = [new ServedApworld('game-1', 'hash-1')];
        $this->reconcile($served, ['hash-1' => $this->verdict('failed', 'boom')]);

        // An admin re-ran the test: pending proves nothing yet.
        $result = $this->reconcile($served, ['hash-1' => $this->verdict('pending')]);

        self::assertSame([], $result->resolvedIncidentIds);
        self::assertCount(1, $this->incidents->findAllActive());
    }

    public function testReturnsTheIdsOfEveryOpenedIncident(): void
    {
        $result = $this->reconcile(
            [new ServedApworld('game-1', 'hash-1'), new ServedApworld('game-2', 'hash-2'), new ServedApworld('game-3', 'hash-3')],
            [
                'hash-1' => $this->verdict('failed', 'one'),
                'hash-2' => $this->verdict('passed'),
                'hash-3' => $this->verdict('failed', 'three'),
            ],
        );

        self::assertCount(2, $result->openedIncidentIds);
        $gameIds = array_map(
            fn (string $id): string => $this->incidents->findById($id)?->getGameId() ?? '',
            $result->openedIncidentIds,
        );
        sort($gameIds);
        self::assertSame(['game-1', 'game-3'], $gameIds);
    }

    /**
     * @param list<ServedApworld>                                                                                    $served
     * @param array<string, array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}> $verdicts
     */
    private function reconcile(array $served, array $verdicts, bool $lockHeld = false): ReconcileApworldIncidentsResult
    {
        $servedQuery = self::createStub(ServedApworldsQueryInterface::class);
        $servedQuery->method('servedApworlds')->willReturn($served);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('fetchApworldPreflights')->willReturn($verdicts);
        $runner->method('runApworldPreflight')->willReturnCallback(function (string $hash): bool {
            $this->retriedHashes[] = $hash;

            return true;
        });

        $reconcile = new ReconcileApworldIncidents(
            $servedQuery,
            $runner,
            $this->incidents,
            new RecordApworldIncident($this->incidents, $this->clock),
            $this->clock,
            new InMemoryExclusivePassLock(held: $lockHeld),
            $this->health,
        );

        return $reconcile->reconcile();
    }

    /**
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}
     */
    private function verdict(string $status, string $error = '', bool $overridden = false, string $checkedAt = '2026-09-24T09:55:00Z', ?string $image = null, ?string $imageId = null): array
    {
        return [
            'status' => $status,
            'error' => $error,
            'checkedAt' => $checkedAt,
            'overridden' => $overridden,
            'blocks' => 'failed' === $status && !$overridden,
            'image' => $image,
            'imageId' => $imageId,
        ];
    }
}
