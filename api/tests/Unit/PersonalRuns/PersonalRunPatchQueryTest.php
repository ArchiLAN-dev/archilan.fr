<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\PersonalRunPatchQuery;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class PersonalRunPatchQueryTest extends TestCase
{
    private const RUN_ID = 'run-0000000000000000000000000001';
    private const SESSION_ID = 'sess-000000000000000000000000001';
    private const USER_ID = 'user-000000000000000000000000001';

    public function testReturnsNullWhenRunMissing(): void
    {
        $query = $this->query(null, null, []);
        self::assertNull($query->forParticipant(self::RUN_ID, self::USER_ID));
    }

    public function testReturnsNullWhenRunNotLaunched(): void
    {
        $run = Run::create('owner-x', 'My run', new \DateTimeImmutable()); // no sessionId
        $query = $this->query($run, null, []);
        self::assertNull($query->forParticipant(self::RUN_ID, self::USER_ID));
    }

    public function testReturnsNullWhenSessionHasNoBridgePort(): void
    {
        $query = $this->query($this->launchedRun(), $this->sessionWithBridgePort(null), [$this->slot('masterkafei_LM')]);
        self::assertNull($query->forParticipant(self::RUN_ID, self::USER_ID));
    }

    public function testReturnsNullWhenUserHasNoSlot(): void
    {
        $query = $this->query($this->launchedRun(), $this->sessionWithBridgePort(35000), []);
        self::assertNull($query->forParticipant(self::RUN_ID, self::USER_ID));
    }

    public function testReturnsBridgePortAndOwnSlotNames(): void
    {
        $query = $this->query(
            $this->launchedRun(),
            $this->sessionWithBridgePort(35000),
            [$this->slot('masterkafei_LM'), $this->slot('masterkafei_SMW')],
        );

        $result = $query->forParticipant(self::RUN_ID, self::USER_ID);

        self::assertNotNull($result);
        self::assertSame(35000, $result['bridgePort']);
        self::assertSame(['masterkafei_LM', 'masterkafei_SMW'], $result['slotNames']);
    }

    /**
     * @param list<SessionSlot> $slots
     */
    private function query(?Run $run, ?Session $session, array $slots): PersonalRunPatchQuery
    {
        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        $slotRepo = $this->createStub(SessionSlotRepositoryInterface::class);
        $slotRepo->method('findByRegistrationAndSession')->willReturn($slots);

        return new PersonalRunPatchQuery($runs, $sessions, $slotRepo);
    }

    private function launchedRun(): Run
    {
        $run = Run::create('owner-x', 'My run', new \DateTimeImmutable());
        $run->setSessionId(self::SESSION_ID);

        return $run;
    }

    private function sessionWithBridgePort(?int $port): Session
    {
        $session = Session::create(self::SESSION_ID, self::RUN_ID, new \DateTimeImmutable());
        $ref = new \ReflectionProperty(Session::class, 'bridgePort');
        $ref->setValue($session, $port);

        return $session;
    }

    private function slot(string $slotName): SessionSlot
    {
        return SessionSlot::create(
            bin2hex(random_bytes(16)),
            self::SESSION_ID,
            self::USER_ID,
            'game-000000000000000000000000001',
            $slotName,
            0,
        );
    }
}
