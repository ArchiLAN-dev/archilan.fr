<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Query\ActiveRegistrationQueryInterface;
use App\Sessions\Application\Query\SessionQuery;
use App\Sessions\Application\Support\SlotsPlayedBy;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Story 17.26: a session only hands out an address while it runs. A stopped, idle or crashed session
 * keeps its last host and port on record, but the orchestrateur has released that port and may have
 * given it to another session, so serving it would send a player to someone else's server.
 */
final class SessionLiveEndpointTest extends TestCase
{
    public function testARunningSessionExposesItsAddress(): void
    {
        $session = $this->runningSession();

        self::assertSame(['host' => 'play.archilan.fr', 'port' => 35007], $session->liveEndpoint());

        $view = $session->payload();
        self::assertSame('play.archilan.fr', $view->host);
        self::assertSame(35007, $view->port);

        $data = $this->queryFor($session)->findById('sess-1');
        self::assertNotNull($data);
        self::assertSame(35007, $data['port']);
        self::assertSame('wss://play.archilan.fr:35007', $data['connectionUri']);
    }

    /**
     * @return iterable<string, array{\Closure(Session): void}>
     */
    public static function notRunning(): iterable
    {
        $now = new \DateTimeImmutable('2026-09-26T12:00:00+00:00');

        yield 'idle' => [static fn (Session $s) => $s->markIdle('saves/a.apsave', false, $now)];
        yield 'stopped' => [static fn (Session $s) => $s->transition(Session::STATUS_STOPPED, $now)];
        yield 'crashed' => [static fn (Session $s) => $s->transition(Session::STATUS_CRASHED, $now)];
        yield 'restarting' => [static function (Session $s) use ($now): void {
            $s->markIdle('saves/a.apsave', false, $now);
            $s->markRestarting($now);
        }];
    }

    /**
     * @param \Closure(Session): void $leaveRunning
     */
    #[DataProvider('notRunning')]
    public function testASessionThatIsNotRunningHidesItsLastAddress(\Closure $leaveRunning): void
    {
        $session = $this->runningSession();
        $leaveRunning($session);

        self::assertNull($session->liveEndpoint());

        $view = $session->payload();
        self::assertNull($view->host);
        self::assertNull($view->port);
        self::assertSame(25007, $view->bridgePort, 'the bridge port stays exposed for internal callers');

        $data = $this->queryFor($session)->findById('sess-1');
        self::assertNotNull($data);
        self::assertNull($data['host']);
        self::assertNull($data['port']);
        self::assertNull($data['connectionUri']);
    }

    private function runningSession(): Session
    {
        return Session::createRunning('sess-1', 'run-1', 'play.archilan.fr', 35007, 'secret', 25007, new \DateTimeImmutable('2026-09-26T10:00:00+00:00'));
    }

    private function queryFor(Session $session): SessionQuery
    {
        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);
        $slots = self::createStub(SessionSlotRepositoryInterface::class);

        return new SessionQuery(
            $sessions,
            self::createStub(ActiveRegistrationQueryInterface::class),
            self::createStub(RunRepositoryInterface::class),
            self::createStub(RunParticipantRepositoryInterface::class),
            self::createStub(RegistrationRepositoryInterface::class),
            $slots,
            self::createStub(SessionPlayersSnapshotRepositoryInterface::class),
            new SlotsPlayedBy($slots, self::createStub(SlotCoPlayerRepositoryInterface::class)),
        );
    }
}
