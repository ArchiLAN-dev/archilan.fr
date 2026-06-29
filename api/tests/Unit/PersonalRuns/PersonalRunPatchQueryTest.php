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
        self::assertNull($this->query(null, null, [])->forParticipant(self::RUN_ID, self::USER_ID));
    }

    public function testReturnsNullWhenRunNotLaunched(): void
    {
        $run = Run::create('owner-x', 'My run', new \DateTimeImmutable()); // no sessionId
        self::assertNull($this->query($run, null, [])->forParticipant(self::RUN_ID, self::USER_ID));
    }

    public function testReturnsNullWhenUserHasNoSlot(): void
    {
        $query = $this->query($this->launchedRun(), $this->session('k.zip'), []);
        self::assertNull($query->forParticipant(self::RUN_ID, self::USER_ID));
    }

    public function testReturnsPersistedOutputKeyAndOwnSlotNames(): void
    {
        $query = $this->query(
            $this->launchedRun(),
            $this->session('custom/output/archive.zip'),
            [$this->slot('masterkafei_LM'), $this->slot('masterkafei_SMW')],
        );

        $result = $query->forParticipant(self::RUN_ID, self::USER_ID);

        self::assertNotNull($result);
        self::assertSame('custom/output/archive.zip', $result['outputKey']);
        self::assertSame(['masterkafei_LM', 'masterkafei_SMW'], $result['slotNames']);
    }

    public function testReturnsAllSessionSlotNamesForAttribution(): void
    {
        $query = $this->query(
            $this->launchedRun(),
            $this->session('custom/output/archive.zip'),
            [$this->slot('master')],
            [$this->slot('master'), $this->slot('master_kafey')],
        );

        $result = $query->forParticipant(self::RUN_ID, self::USER_ID);

        self::assertNotNull($result);
        self::assertSame(['master'], $result['slotNames']);
        self::assertSame(['master', 'master_kafey'], $result['allSlotNames']);
    }

    public function testFallsBackToDeterministicKeyWhenSessionKeyAbsent(): void
    {
        $query = $this->query($this->launchedRun(), $this->session(null), [$this->slot('masterkafei_LM')]);

        $result = $query->forParticipant(self::RUN_ID, self::USER_ID);

        self::assertNotNull($result);
        self::assertSame(self::SESSION_ID.'/output/archive.zip', $result['outputKey']);
    }

    public function testFallsBackToDeterministicKeyWhenSessionMissing(): void
    {
        $query = $this->query($this->launchedRun(), null, [$this->slot('masterkafei_LM')]);

        $result = $query->forParticipant(self::RUN_ID, self::USER_ID);

        self::assertNotNull($result);
        self::assertSame(self::SESSION_ID.'/output/archive.zip', $result['outputKey']);
    }

    /**
     * @param list<SessionSlot>      $slots    the caller's own slots
     * @param list<SessionSlot>|null $allSlots every slot in the session (defaults to $slots)
     */
    private function query(?Run $run, ?Session $session, array $slots, ?array $allSlots = null): PersonalRunPatchQuery
    {
        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        $slotRepo = $this->createStub(SessionSlotRepositoryInterface::class);
        $slotRepo->method('findByRegistrationAndSession')->willReturn($slots);
        $slotRepo->method('findBySessionId')->willReturn($allSlots ?? $slots);

        return new PersonalRunPatchQuery($runs, $sessions, $slotRepo);
    }

    private function launchedRun(): Run
    {
        $run = Run::create('owner-x', 'My run', new \DateTimeImmutable());
        $run->setSessionId(self::SESSION_ID);

        return $run;
    }

    private function session(?string $generatedOutputKey): Session
    {
        $session = Session::create(self::SESSION_ID, self::RUN_ID, new \DateTimeImmutable());
        if (null !== $generatedOutputKey) {
            $session->setGeneratedOutputKey($generatedOutputKey);
        }

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
