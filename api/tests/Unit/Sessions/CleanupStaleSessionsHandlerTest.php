<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\ScheduledTask\CleanupStaleSessionsHandler;
use App\Sessions\Application\ScheduledTask\CleanupStaleSessionsTask;
use App\Sessions\Application\SessionReconcilerInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CleanupStaleSessionsHandlerTest extends TestCase
{
    public function testStaleSessionIsDelegatedToReconciler(): void
    {
        $session = $this->makeSession(Session::STATUS_RESTARTING, '-30 minutes');

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findByStatuses')->willReturn([$session]);

        $reconciler = $this->createMock(SessionReconcilerInterface::class);
        $reconciler->expects($this->once())
            ->method('reconcilePending')
            ->with($session->getId())
            ->willReturn(['found' => true, 'from' => Session::STATUS_RESTARTING, 'action' => 'forced_idle', 'to' => Session::STATUS_IDLE]);

        $this->makeHandler($sessions, $reconciler)(new CleanupStaleSessionsTask());
    }

    public function testFreshSessionIsLeftUntouched(): void
    {
        // A session that has not crossed its threshold must not be reconciled.
        $session = $this->makeSession(Session::STATUS_RESTARTING, 'now');

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findByStatuses')->willReturn([$session]);

        $reconciler = $this->createMock(SessionReconcilerInterface::class);
        $reconciler->expects($this->never())->method('reconcilePending');

        $this->makeHandler($sessions, $reconciler)(new CleanupStaleSessionsTask());
    }

    public function testReconcilerSkipDoesNotCount(): void
    {
        // A reconciler that reports "skipped" (nothing to do) must not raise the handler.
        $session = $this->makeSession(Session::STATUS_RUNNING, '-30 minutes');

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findByStatuses')->willReturn([$session]);

        $reconciler = $this->createMock(SessionReconcilerInterface::class);
        $reconciler->expects($this->once())
            ->method('reconcilePending')
            ->willReturn(['found' => true, 'from' => Session::STATUS_RUNNING, 'action' => 'skipped', 'to' => null]);

        $this->makeHandler($sessions, $reconciler)(new CleanupStaleSessionsTask());
    }

    private function makeSession(string $targetStatus, string $clock): Session
    {
        $at = new \DateTimeImmutable($clock);
        $session = Session::create('sess-test', 'evt-001', $at);

        $path = [
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
            Session::STATUS_RUNNING,
        ];

        foreach ($path as $step) {
            if (Session::STATUS_RUNNING === $step) {
                $session->transition($step, $at, 'bridge.local', 38281, 'secret');
            } else {
                $session->transition($step, $at);
            }
            if ($step === $targetStatus) {
                return $session;
            }
        }

        if (Session::STATUS_RESTARTING === $targetStatus) {
            $session->markIdle('sessions/abc/save.apsave', false, $at);
            $session->markRestarting($at);
        }

        return $session;
    }

    private function makeHandler(
        SessionRepositoryInterface $sessions,
        SessionReconcilerInterface $reconciler,
    ): CleanupStaleSessionsHandler {
        return new CleanupStaleSessionsHandler($sessions, $reconciler, new NullLogger());
    }
}
