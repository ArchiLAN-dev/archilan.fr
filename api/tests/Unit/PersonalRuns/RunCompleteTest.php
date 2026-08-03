<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\Entity\Run;
use PHPUnit\Framework\TestCase;

final class RunCompleteTest extends TestCase
{
    public function testCompleteSetsCompletedAndClearsConnection(): void
    {
        $now = new \DateTimeImmutable('2026-06-20T10:00:00+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->attachSession('sess-1');
        $run->start($now);
        $run->markRunning('runner.example.com', 38281, $now, 'deadbeef12345678');

        $run->complete($now);

        self::assertSame(Run::STATUS_COMPLETED, $run->getStatus());
        self::assertNull($run->getConnectionHost());
        self::assertNull($run->getConnectionPort());
        self::assertNull($run->getConnectionPassword());
    }

    public function testCompleteThrowsWhenRunNotActive(): void
    {
        $now = new \DateTimeImmutable('2026-06-20T10:00:00+00:00');
        $run = Run::create('owner-1', 'My run', $now); // draft

        $this->expectException(\DomainException::class);
        $run->complete($now);
    }

    // ─── the runner stopping must never reopen a finished run (story 17.25) ─────

    public function testMarkStoppedLeavesACompletedRunAlone(): void
    {
        $now = new \DateTimeImmutable('2026-08-02T22:54:36+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->attachSession('sess-1');
        $run->start($now);
        $run->markRunning('runner.example.com', 38281, $now, 'deadbeef12345678');
        $run->complete($now);

        // The orchestrateur stops the container ~20 s later and the webhook lands here.
        $run->markStopped($now->modify('+23 seconds'));

        self::assertSame(Run::STATUS_COMPLETED, $run->getStatus(), 'stopping the runner must not undo the finish');
    }

    public function testMarkStoppedLeavesACancelledRunAlone(): void
    {
        $now = new \DateTimeImmutable('2026-08-02T22:54:36+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->cancel($now);

        $run->markStopped($now->modify('+1 minute'));

        self::assertSame(Run::STATUS_CANCELLED, $run->getStatus());
    }

    public function testMarkStoppedStillIdlesARunningRun(): void
    {
        $now = new \DateTimeImmutable('2026-08-02T22:54:36+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->attachSession('sess-1');
        $run->start($now);
        $run->markRunning('runner.example.com', 38281, $now, 'deadbeef12345678');

        $run->markStopped($now->modify('+1 minute'));

        self::assertSame(Run::STATUS_IDLE, $run->getStatus());
        self::assertNull($run->getConnectionHost());
    }

    public function testMarkSessionFinishedCompletesANonTerminalRun(): void
    {
        $now = new \DateTimeImmutable('2026-08-02T22:54:36+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->attachSession('sess-1');
        $run->start($now);
        $run->markRunning('runner.example.com', 38281, $now, 'deadbeef12345678');
        $run->stop($now);

        $run->markSessionFinished($now->modify('+5 minutes'));

        self::assertSame(Run::STATUS_COMPLETED, $run->getStatus());
        self::assertNull($run->getConnectionPassword());
    }

    public function testMarkSessionFinishedLeavesACancelledRunAlone(): void
    {
        $now = new \DateTimeImmutable('2026-08-02T22:54:36+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->cancel($now);

        $run->markSessionFinished($now->modify('+5 minutes'));

        self::assertSame(Run::STATUS_CANCELLED, $run->getStatus());
    }

    // ─── terminal read-only guard (issue #338) ──────────────────────────────────

    public function testDraftRunIsNotTerminal(): void
    {
        $run = Run::create('owner-1', 'My run', new \DateTimeImmutable());

        self::assertFalse($run->isTerminal());
    }

    public function testCompletedRunIsTerminal(): void
    {
        self::assertTrue($this->completedRun()->isTerminal());
    }

    public function testCancelledRunIsTerminal(): void
    {
        $now = new \DateTimeImmutable('2026-06-20T10:00:00+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->cancel($now);

        self::assertTrue($run->isTerminal());
    }

    public function testRegenerateInviteTokenRotatesOnDraft(): void
    {
        $now = new \DateTimeImmutable('2026-06-20T10:00:00+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $before = $run->getInviteToken();

        $run->regenerateInviteToken($now);

        self::assertNotSame($before, $run->getInviteToken());
    }

    public function testRegenerateInviteTokenThrowsOnTerminalRun(): void
    {
        $run = $this->completedRun();

        $this->expectException(\DomainException::class);
        $run->regenerateInviteToken(new \DateTimeImmutable());
    }

    private function completedRun(): Run
    {
        $now = new \DateTimeImmutable('2026-06-20T10:00:00+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->attachSession('sess-1');
        $run->start($now);
        $run->markRunning('runner.example.com', 38281, $now, 'deadbeef12345678');
        $run->complete($now);

        return $run;
    }
}
