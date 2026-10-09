<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\Entity\RunInvitation;
use PHPUnit\Framework\TestCase;

/**
 * Story 43.1: when a friend may be invited again into the same run.
 */
final class RunInvitationTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-09T12:00:00+00:00');
    }

    public function testAPendingOrAcceptedInvitationIsNotSentAgain(): void
    {
        $invitation = RunInvitation::send('run-1', 'friend', 'owner', $this->now);
        self::assertFalse($invitation->canBeSentAgain($this->now->modify('+3 days')));

        $invitation->accept($this->now);
        self::assertFalse($invitation->canBeSentAgain($this->now->modify('+3 days')));
    }

    public function testADeclinedInvitationWaitsADay(): void
    {
        $invitation = RunInvitation::send('run-1', 'friend', 'owner', $this->now);
        $invitation->decline($this->now);

        self::assertFalse($invitation->canBeSentAgain($this->now->modify('+23 hours')));
        self::assertTrue($invitation->canBeSentAgain($this->now->modify('+24 hours')));
    }

    public function testAClosedInvitationCanBeSentAgainAtOnce(): void
    {
        $invitation = RunInvitation::send('run-1', 'friend', 'owner', $this->now);
        $invitation->close($this->now);
        self::assertTrue($invitation->canBeSentAgain($this->now));

        $later = $this->now->modify('+1 hour');
        $invitation->resend('owner', $later);
        self::assertTrue($invitation->isPending());
        self::assertSame($later, $invitation->getInvitedAt());
        self::assertNull($invitation->getRespondedAt());
    }
}
