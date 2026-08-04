<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\Entity\Run;
use PHPUnit\Framework\TestCase;

/**
 * Story 16.13: the run no longer invents a connection password, and the session is authoritative on
 * it - including when the session has none.
 */
final class RunConnectionPasswordTest extends TestCase
{
    private function draftRun(): Run
    {
        return Run::create('user-1', 'Ma run', new \DateTimeImmutable('2026-08-04 12:00:00'));
    }

    public function testStartingARunDoesNotInventAPassword(): void
    {
        $run = $this->draftRun();

        $run->start(new \DateTimeImmutable('2026-08-04 12:01:00'));

        // It used to be generated here, before anything knew whether this run wanted one.
        self::assertNull($run->getConnectionPassword());
        self::assertSame(Run::STATUS_STARTING, $run->getStatus());
    }

    public function testTheSessionPasswordIsAdoptedWhenTheRunGoesActive(): void
    {
        $run = $this->draftRun();
        $run->start(new \DateTimeImmutable('2026-08-04 12:01:00'));

        $run->markRunning('archilan.fr', 38281, new \DateTimeImmutable('2026-08-04 12:02:00'), 'from-session');

        self::assertSame('from-session', $run->getConnectionPassword());
        self::assertSame('archilan.fr', $run->getConnectionHost());
        self::assertSame(38281, $run->getConnectionPort());
    }

    public function testARunLaunchedWithoutAPasswordShowsNone(): void
    {
        $run = $this->draftRun();
        $run->start(new \DateTimeImmutable('2026-08-04 12:01:00'));

        $run->markRunning('archilan.fr', 38281, new \DateTimeImmutable('2026-08-04 12:02:00'), null);

        self::assertNull($run->getConnectionPassword());
        // Host and port still answer: they are what a player needs to connect.
        self::assertSame('archilan.fr', $run->getConnectionHost());
        self::assertSame(38281, $run->getConnectionPort());
    }

    /**
     * The assignment used to be skipped on null, which mattered the moment a run could legitimately
     * have no password: it would have kept displaying the secret from its previous launch.
     */
    public function testDroppingThePasswordBetweenTwoLaunchesClearsIt(): void
    {
        $run = $this->draftRun();
        $run->start(new \DateTimeImmutable('2026-08-04 12:01:00'));
        $run->markRunning('archilan.fr', 38281, new \DateTimeImmutable('2026-08-04 12:02:00'), 'old-secret');

        $run->markRunning('archilan.fr', 38281, new \DateTimeImmutable('2026-08-04 12:30:00'), null);

        self::assertNull($run->getConnectionPassword());
    }
}
