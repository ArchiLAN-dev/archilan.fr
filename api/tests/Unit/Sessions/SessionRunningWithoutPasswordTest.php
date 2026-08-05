<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Domain\Entity\Session;
use PHPUnit\Framework\TestCase;

/**
 * Story 16.13: a session may run without a join password, so the password must not be a
 * precondition for reaching `running`.
 *
 * The guard was written when a password was always minted, and it left an open server stuck in
 * `launching` - the Archipelago server accepting connections while the site claimed it was still
 * starting. Host and port stay required: without them a player has nothing to connect to.
 */
final class SessionRunningWithoutPasswordTest extends TestCase
{
    private const string NOW = '2026-08-05 08:00:00';

    private function launchingSession(): Session
    {
        $now = new \DateTimeImmutable(self::NOW);
        $session = Session::create('sess-1', 'event-1', $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->transition(Session::STATUS_LAUNCHING, $now);

        return $session;
    }

    public function testASessionReachesRunningWithoutAJoinPassword(): void
    {
        $session = $this->launchingSession();

        $session->transition(Session::STATUS_RUNNING, new \DateTimeImmutable(self::NOW), 'localhost', 35000);

        self::assertSame(Session::STATUS_RUNNING, $session->getStatus());
        self::assertSame('localhost', $session->getHost());
        self::assertSame(35000, $session->getPort());
        self::assertNull($session->getPassword());
    }

    public function testAReportedPasswordIsStored(): void
    {
        $session = $this->launchingSession();

        $session->transition(Session::STATUS_RUNNING, new \DateTimeImmutable(self::NOW), 'localhost', 35000, 'hunter2');

        self::assertSame('hunter2', $session->getPassword());
    }

    /** The launch stored the authoritative value; a silent runner report must not erase it. */
    public function testASilentReportDoesNotEraseTheStoredPassword(): void
    {
        $session = $this->launchingSession();
        $session->applyJoinPassword('from-launch');

        $session->transition(Session::STATUS_RUNNING, new \DateTimeImmutable(self::NOW), 'localhost', 35000);

        self::assertSame('from-launch', $session->getPassword());
    }

    public function testHostAndPortAreStillRequired(): void
    {
        $session = $this->launchingSession();

        $this->expectException(\LogicException::class);
        $session->transition(Session::STATUS_RUNNING, new \DateTimeImmutable(self::NOW), 'localhost', null);
    }

    public function testABlankHostIsStillRejected(): void
    {
        $session = $this->launchingSession();

        $this->expectException(\LogicException::class);
        $session->transition(Session::STATUS_RUNNING, new \DateTimeImmutable(self::NOW), '  ', 35000);
    }
}
