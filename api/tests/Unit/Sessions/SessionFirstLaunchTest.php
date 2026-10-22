<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\Entity\Session;
use PHPUnit\Framework\TestCase;

/**
 * Story 32.24: `firstLaunchedAt` is set at the first `running` and never moves; `startedAt` is the last launch on
 * every path, the resume from a save included.
 */
final class SessionFirstLaunchTest extends TestCase
{
    public function testFirstRunningSetsBothLaunchInstants(): void
    {
        $session = $this->launched('2026-10-22T09:00:00+00:00');

        self::assertEquals(new \DateTimeImmutable('2026-10-22T09:00:00+00:00'), $session->getFirstLaunchedAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-22T09:00:00+00:00'), $session->getStartedAt());
        self::assertSame('2026-10-22T09:00:00+00:00', $session->payload()->firstLaunchedAt);
    }

    public function testResumeFromSaveMovesTheLastLaunchOnly(): void
    {
        $session = $this->launched('2026-10-22T09:00:00+00:00');
        $session->markIdle('save.zip', false, new \DateTimeImmutable('2026-10-22T10:00:00+00:00'));
        $session->markRestarting(new \DateTimeImmutable('2026-10-23T18:00:00+00:00'));
        $session->resumeRunning('10.0.0.1', 38281, null, new \DateTimeImmutable('2026-10-23T18:01:00+00:00'));

        self::assertEquals(new \DateTimeImmutable('2026-10-22T09:00:00+00:00'), $session->getFirstLaunchedAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-23T18:01:00+00:00'), $session->getStartedAt());
    }

    public function testCrashRestartKeepsTheFirstLaunch(): void
    {
        $session = $this->launched('2026-10-22T09:00:00+00:00');
        $session->transition(Session::STATUS_CRASHED, new \DateTimeImmutable('2026-10-22T09:30:00+00:00'));
        $session->transition(Session::STATUS_LAUNCHING, new \DateTimeImmutable('2026-10-22T09:31:00+00:00'));
        $session->transition(Session::STATUS_RUNNING, new \DateTimeImmutable('2026-10-22T09:32:00+00:00'), '10.0.0.1', 38281);

        self::assertEquals(new \DateTimeImmutable('2026-10-22T09:00:00+00:00'), $session->getFirstLaunchedAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-22T09:32:00+00:00'), $session->getStartedAt());
    }

    public function testForcedRelaunchAfterTheEndKeepsTheFirstLaunch(): void
    {
        $session = $this->launched('2026-10-22T09:00:00+00:00');
        $session->transition(Session::STATUS_FINISHED, new \DateTimeImmutable('2026-10-22T12:00:00+00:00'));
        $session->transition(Session::STATUS_LAUNCHING, new \DateTimeImmutable('2026-10-23T08:00:00+00:00'));
        $session->transition(Session::STATUS_RUNNING, new \DateTimeImmutable('2026-10-23T08:01:00+00:00'), '10.0.0.1', 38281);

        self::assertEquals(new \DateTimeImmutable('2026-10-22T09:00:00+00:00'), $session->getFirstLaunchedAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-23T08:01:00+00:00'), $session->getStartedAt());
    }

    public function testForceResetKeepsTheFirstLaunch(): void
    {
        $session = $this->launched('2026-10-22T09:00:00+00:00');
        $session->forceReset(new \DateTimeImmutable('2026-10-22T11:00:00+00:00'));

        self::assertEquals(new \DateTimeImmutable('2026-10-22T09:00:00+00:00'), $session->getFirstLaunchedAt());
    }

    public function testCreateRunningIsAFirstLaunch(): void
    {
        $now = new \DateTimeImmutable('2026-10-22T09:00:00+00:00');
        $session = Session::createRunning('s-weekly', 'weekly-1', '10.0.0.1', 38281, null, null, $now);

        self::assertEquals($now, $session->getFirstLaunchedAt());
    }

    public function testNeverLaunchedSessionHasNoFirstLaunch(): void
    {
        $session = Session::create('s-draft', 'evt-1', new \DateTimeImmutable('2026-10-22T08:00:00+00:00'));

        self::assertNull($session->getFirstLaunchedAt());
    }

    private function launched(string $at): Session
    {
        $now = new \DateTimeImmutable($at);
        $session = Session::create('s-'.md5($at), 'evt-1', $now->modify('-1 hour'));
        foreach ([
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
        ] as $status) {
            $session->transition($status, $now);
        }
        $session->transition(Session::STATUS_RUNNING, $now, '10.0.0.1', 38281);

        return $session;
    }
}
