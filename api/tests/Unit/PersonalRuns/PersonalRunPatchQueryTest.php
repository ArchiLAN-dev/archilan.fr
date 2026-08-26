<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Query\PersonalRunPatchQuery;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Support\SlotsPlayedBy;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class PersonalRunPatchQueryTest extends TestCase
{
    private const string RUN_ID = 'run-0000000000000000000000000001';
    private const string SESSION_ID = 'sess-000000000000000000000000001';
    private const string USER_ID = 'user-000000000000000000000000001';

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
     * Story 16.17: a slot played by several people yields its patch to all of them. Before this the
     * co-player of a shared Minecraft world could not download the patch of the game they played.
     */
    public function testCoPlayerGetsThePatchOfASlotTheyDoNotOwn(): void
    {
        $shared = $this->slot('alice_MC', 'someone-else', 'game-slot-1');

        $query = $this->query(
            $this->launchedRun(),
            $this->session('runs/out.zip'),
            [],
            [$shared],
            ['game-slot-1'],
        );

        $result = $query->forParticipant(self::RUN_ID, self::USER_ID);

        self::assertNotNull($result);
        self::assertSame(['alice_MC'], $result['slotNames']);
    }

    /** Co-playing nothing in this session still means no patch at all. */
    public function testNonPlayerGetsNothing(): void
    {
        $query = $this->query(
            $this->launchedRun(),
            $this->session('runs/out.zip'),
            [],
            [$this->slot('alice_MC', 'someone-else', 'game-slot-1')],
        );

        self::assertNull($query->forParticipant(self::RUN_ID, self::USER_ID));
    }

    /**
     * @param list<SessionSlot>      $slots           the caller's own slots
     * @param list<SessionSlot>|null $allSlots        every slot in the session (defaults to $slots)
     * @param list<string>           $coPlayedSlotIds game slot ids the caller co-plays (story 16.17)
     */
    private function query(?Run $run, ?Session $session, array $slots, ?array $allSlots = null, array $coPlayedSlotIds = []): PersonalRunPatchQuery
    {
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        $slotRepo = self::createStub(SessionSlotRepositoryInterface::class);
        $slotRepo->method('findByRegistrationAndSession')->willReturn($slots);
        $slotRepo->method('findBySessionId')->willReturn($allSlots ?? $slots);

        $coPlayers = self::createStub(SlotCoPlayerRepositoryInterface::class);
        $coPlayers->method('findSlotIdsForUser')->willReturn($coPlayedSlotIds);

        return new PersonalRunPatchQuery($runs, $sessions, $slotRepo, new SlotsPlayedBy($slotRepo, $coPlayers));
    }

    private function launchedRun(): Run
    {
        $run = Run::create('owner-x', 'My run', new \DateTimeImmutable());
        $run->attachSession(self::SESSION_ID);

        return $run;
    }

    private function session(?string $generatedOutputKey): Session
    {
        $session = Session::create(self::SESSION_ID, self::RUN_ID, new \DateTimeImmutable());
        if (null !== $generatedOutputKey) {
            $session->markGenerated($generatedOutputKey);
        }

        return $session;
    }

    private function slot(string $slotName, string $registrationId = self::USER_ID, ?string $gameSlotId = null): SessionSlot
    {
        return SessionSlot::create(
            bin2hex(random_bytes(16)),
            self::SESSION_ID,
            $registrationId,
            'game-000000000000000000000000001',
            $slotName,
            0,
            $gameSlotId,
        );
    }
}
