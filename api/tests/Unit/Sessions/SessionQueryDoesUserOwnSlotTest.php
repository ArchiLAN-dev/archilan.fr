<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Query\ActiveRegistrationQueryInterface;
use App\Sessions\Application\Query\SessionQuery;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Slot-ownership gate behind the #252 / #253 fix: session-level authorization is not enough, the
 * caller must own the slot at the requested index. Event sessions key slots by registration id;
 * personal-run sessions key them by the participant userId (see LaunchPersonalRunJobHandler).
 *
 * The requested index is the *Archipelago* slot number - what the bridge indexes its state by and
 * what the slot pages carry in their URL. It is not SessionSlot::getSlotOrder(), which ranks a game
 * inside one participant's own list, so every player's first game carries slot_order 1. Comparing
 * the two denied every non-admin on every session; the index is now resolved to its generated slot
 * name through the players snapshot, the same join the rest of the code uses.
 */
final class SessionQueryDoesUserOwnSlotTest extends TestCase
{
    private const string SESSION_ID = 'sess-1';
    private const string EVENT_ID = 'evt-1';
    private const string USER_ID = 'user-alice';

    public function testEventSessionAllowsOwnSlot(): void
    {
        $query = $this->buildQuery(
            personalRun: null,
            registration: $this->registration('reg-alice'),
            slots: [$this->slot('reg-alice', 'Alice_A'), $this->slot('reg-bob', 'Bob_B')],
            apSlotNames: [2 => 'Alice_A', 3 => 'Bob_B'],
        );

        self::assertTrue($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 2));
    }

    public function testEventSessionDeniesForeignSlot(): void
    {
        $query = $this->buildQuery(
            personalRun: null,
            registration: $this->registration('reg-alice'),
            slots: [$this->slot('reg-alice', 'Alice_A'), $this->slot('reg-bob', 'Bob_B')],
            apSlotNames: [2 => 'Alice_A', 3 => 'Bob_B'],
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 3));
    }

    public function testEventSessionDeniesWhenCallerHasNoRegistration(): void
    {
        $query = $this->buildQuery(
            personalRun: null,
            registration: null,
            slots: [$this->slot('reg-bob', 'Bob_B')],
            apSlotNames: [2 => 'Bob_B'],
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 2));
    }

    public function testPersonalRunSessionAllowsOwnSlotKeyedByUserId(): void
    {
        // Personal-run sessions store the participant userId in the SessionSlot registrationId column.
        $query = $this->buildQuery(
            personalRun: Run::create(self::USER_ID, 'Run', new \DateTimeImmutable()),
            registration: null,
            slots: [$this->slot(self::USER_ID, 'Alice_A')],
            apSlotNames: [2 => 'Alice_A'],
        );

        self::assertTrue($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 2));
    }

    public function testPersonalRunSessionDeniesForeignSlot(): void
    {
        $query = $this->buildQuery(
            personalRun: Run::create(self::USER_ID, 'Run', new \DateTimeImmutable()),
            registration: null,
            slots: [$this->slot('user-bob', 'Bob_B')],
            apSlotNames: [2 => 'Bob_B'],
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 2));
    }

    /**
     * The regression itself: a participant's only game ranks slot_order 1, and Archipelago slot 1 is
     * the injected `_bridge_observer` spectator - never a player. Matching the two numbers denied
     * every non-admin on hints, hint purchases, hint status and item locations.
     */
    public function testFirstGameDoesNotGrantAccessToTheBridgeSlot(): void
    {
        $query = $this->buildQuery(
            personalRun: Run::create(self::USER_ID, 'Run', new \DateTimeImmutable()),
            registration: null,
            slots: [$this->slot(self::USER_ID, 'Alice_A', slotOrder: 1)],
            apSlotNames: [1 => 'Bridge', 2 => 'Alice_A'],
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 1));
        self::assertTrue($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 2));
    }

    public function testUnknownArchipelagoSlotIsDenied(): void
    {
        $query = $this->buildQuery(
            personalRun: Run::create(self::USER_ID, 'Run', new \DateTimeImmutable()),
            registration: null,
            slots: [$this->slot(self::USER_ID, 'Alice_A')],
            apSlotNames: [2 => 'Alice_A'],
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 9));
    }

    /** Fail-closed: without a players push there is no proof of who holds which Archipelago slot. */
    public function testMissingSnapshotIsDenied(): void
    {
        $query = $this->buildQuery(
            personalRun: Run::create(self::USER_ID, 'Run', new \DateTimeImmutable()),
            registration: null,
            slots: [$this->slot(self::USER_ID, 'Alice_A')],
            apSlotNames: null,
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 2));
    }

    public function testUnknownSessionIsDenied(): void
    {
        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn(null);

        $query = new SessionQuery(
            $sessions,
            self::createStub(ActiveRegistrationQueryInterface::class),
            self::createStub(RunRepositoryInterface::class),
            self::createStub(RunParticipantRepositoryInterface::class),
            self::createStub(RegistrationRepositoryInterface::class),
            self::createStub(SessionSlotRepositoryInterface::class),
            self::createStub(SessionPlayersSnapshotRepositoryInterface::class),
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 2));
    }

    /**
     * @param list<SessionSlot>       $slots
     * @param array<int, string>|null $apSlotNames Archipelago slot number => generated slot name,
     *                                             null when the bridge never pushed a state
     */
    private function buildQuery(
        ?Run $personalRun,
        ?Registration $registration,
        array $slots,
        ?array $apSlotNames,
    ): SessionQuery {
        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn(
            Session::create(self::SESSION_ID, self::EVENT_ID, new \DateTimeImmutable()),
        );

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findBySessionId')->willReturn($personalRun);

        $registrations = self::createStub(RegistrationRepositoryInterface::class);
        $registrations->method('findByEventAndUser')->willReturn($registration);

        $slotsByName = [];
        foreach ($slots as $slot) {
            $slotsByName[$slot->getSlotName()] = $slot;
        }
        $slotRepo = self::createStub(SessionSlotRepositoryInterface::class);
        $slotRepo->method('findBySessionAndSlotName')->willReturnCallback(
            static fn (string $sessionId, string $slotName): ?SessionSlot => $slotsByName[$slotName] ?? null,
        );

        $snapshots = self::createStub(SessionPlayersSnapshotRepositoryInterface::class);
        $snapshots->method('findBySessionId')->willReturn(
            null === $apSlotNames ? null : $this->snapshot($apSlotNames),
        );

        return new SessionQuery(
            $sessions,
            self::createStub(ActiveRegistrationQueryInterface::class),
            $runs,
            self::createStub(RunParticipantRepositoryInterface::class),
            $registrations,
            $slotRepo,
            $snapshots,
        );
    }

    /**
     * The bridge pushes its state verbatim: slots keyed by Archipelago slot number, snake_case
     * fields. Doctrine's json type decodes those numeric keys back to int offsets.
     *
     * @param array<int, string> $apSlotNames
     */
    private function snapshot(array $apSlotNames): SessionPlayersSnapshot
    {
        $slots = [];
        foreach ($apSlotNames as $apSlot => $slotName) {
            $slots[$apSlot] = ['slot_name' => $slotName, 'checks_done' => 0];
        }

        return new SessionPlayersSnapshot(self::SESSION_ID, ['slots' => $slots], new \DateTimeImmutable());
    }

    private function registration(string $id): Registration
    {
        $now = new \DateTimeImmutable();

        return new Registration($id, self::EVENT_ID, self::USER_ID, Registration::STATUS_RESERVED, $now, $now, []);
    }

    private function slot(string $registrationId, string $slotName, int $slotOrder = 1): SessionSlot
    {
        return SessionSlot::create(
            'slot-'.$slotName,
            self::SESSION_ID,
            $registrationId,
            'game-'.$slotName,
            $slotName,
            $slotOrder,
        );
    }
}
