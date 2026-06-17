<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Handler\ReconcileStuckRunsHandler;
use App\PersonalRuns\Application\Message\ReconcileStuckRunsMessage;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ReconcileStuckRunsHandlerTest extends TestCase
{
    // Older than the longest stuck threshold (STARTING = 30 min) so every transitional run counts.
    private const OLD = '-40 minutes';

    public function testStuckStoppingWithResolvedSessionGoesIdle(): void
    {
        $run = $this->makeStuckRun(Run::STATUS_STOPPING);
        $session = $this->makeSession(Session::STATUS_STOPPED);

        $this->handle($run, $session);

        self::assertSame(Run::STATUS_IDLE, $run->getStatus());
    }

    public function testStuckStoppingWithRunningSessionGoesActive(): void
    {
        $run = $this->makeStuckRun(Run::STATUS_STOPPING);
        $session = $this->makeSession(Session::STATUS_RUNNING);

        $this->handle($run, $session);

        self::assertSame(Run::STATUS_ACTIVE, $run->getStatus());
    }

    public function testStuckStartingWithFailedSessionResetsToDraft(): void
    {
        $run = $this->makeStuckRun(Run::STATUS_STARTING);
        $session = $this->makeSession(Session::STATUS_FAILED);

        $this->handle($run, $session);

        self::assertSame(Run::STATUS_DRAFT, $run->getStatus());
    }

    public function testStuckStoppingWithMissingSessionGoesIdle(): void
    {
        $run = $this->makeStuckRun(Run::STATUS_STOPPING);

        $this->handle($run, null);

        self::assertSame(Run::STATUS_IDLE, $run->getStatus());
    }

    public function testStuckRestartingWithTransitionalSessionIsLeftAlone(): void
    {
        $run = $this->makeStuckRun(Run::STATUS_RESTARTING);
        $session = $this->makeSession(Session::STATUS_GENERATING);

        $this->handle($run, $session);

        // The session watchdog will resolve the session first; the run follows next pass.
        self::assertSame(Run::STATUS_RESTARTING, $run->getStatus());
    }

    public function testFreshRunIsLeftAlone(): void
    {
        $run = Run::create('owner-1', 'Fresh', new \DateTimeImmutable());
        $run->start(new \DateTimeImmutable()); // starting, updatedAt = now

        $this->handle($run, null);

        self::assertSame(Run::STATUS_STARTING, $run->getStatus());
    }

    private function handle(Run $run, ?Session $session): void
    {
        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findByStatuses')->willReturn([$run]);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        (new ReconcileStuckRunsHandler($runs, $sessions, new NullLogger()))(new ReconcileStuckRunsMessage());
    }

    private function makeStuckRun(string $status): Run
    {
        $old = new \DateTimeImmutable(self::OLD);
        $run = Run::create('owner-1', 'Stuck', $old);
        $run->setSessionId('sess-1');

        $run->start($old); // → starting
        if (Run::STATUS_STARTING === $status) {
            return $run;
        }

        $run->markRunning('10.0.0.1', 9042, $old); // → active
        if (Run::STATUS_RESTARTING === $status) {
            $run->markRestarting($old);

            return $run;
        }

        $run->stop($old); // → stopping

        return $run;
    }

    private function makeSession(string $status): Session
    {
        $now = new \DateTimeImmutable();
        $session = Session::create('sess-1', 'evt-1', $now);

        foreach ([
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
        ] as $step) {
            $session->transition($step, $now);
            if ($step === $status) {
                return $session;
            }
        }

        foreach ([Session::STATUS_GENERATED, Session::STATUS_LAUNCHING] as $step) {
            $session->transition($step, $now);
        }
        $session->transition(Session::STATUS_RUNNING, $now, '10.0.0.1', 9042, 'secret');

        if (Session::STATUS_RUNNING === $status) {
            return $session;
        }
        if (Session::STATUS_STOPPED === $status) {
            $session->transition(Session::STATUS_STOPPED, $now);

            return $session;
        }
        if (Session::STATUS_FAILED === $status) {
            // running → can't go failed directly; emulate a generating-failure session instead.
            $fresh = Session::create('sess-1', 'evt-1', $now);
            $fresh->transition(Session::STATUS_VALIDATING, $now);
            $fresh->transition(Session::STATUS_READY, $now);
            $fresh->transition(Session::STATUS_GENERATING, $now);
            $fresh->transition(Session::STATUS_FAILED, $now);

            return $fresh;
        }

        return $session;
    }
}
