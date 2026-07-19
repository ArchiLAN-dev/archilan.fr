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
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Slot-ownership gate behind the #252 / #253 fix: session-level authorization is not enough, the
 * caller must own the slot at the requested index. Event sessions key slots by registration id;
 * personal-run sessions key them by the participant userId (see LaunchPersonalRunJobHandler).
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
            slots: [$this->slot('reg-alice', 0), $this->slot('reg-alice', 1)],
        );

        self::assertTrue($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 1));
    }

    public function testEventSessionDeniesForeignSlot(): void
    {
        // Alice owns only slot 0; slot 1 is another registrant's.
        $query = $this->buildQuery(
            personalRun: null,
            registration: $this->registration('reg-alice'),
            slots: [$this->slot('reg-alice', 0)],
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 1));
    }

    public function testEventSessionDeniesWhenCallerHasNoRegistration(): void
    {
        $query = $this->buildQuery(personalRun: null, registration: null, slots: []);

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 0));
    }

    public function testPersonalRunSessionAllowsOwnSlotKeyedByUserId(): void
    {
        // Personal-run sessions store the participant userId in the SessionSlot registrationId column.
        $query = $this->buildQuery(
            personalRun: Run::create(self::USER_ID, 'Run', new \DateTimeImmutable()),
            registration: null,
            slots: [$this->slot(self::USER_ID, 3)],
        );

        self::assertTrue($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 3));
    }

    public function testPersonalRunSessionDeniesForeignSlot(): void
    {
        $query = $this->buildQuery(
            personalRun: Run::create(self::USER_ID, 'Run', new \DateTimeImmutable()),
            registration: null,
            slots: [], // the userId keys no slot at this index
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 3));
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
        );

        self::assertFalse($query->doesUserOwnSlot(self::USER_ID, self::SESSION_ID, 0));
    }

    /**
     * @param list<SessionSlot> $slots
     */
    private function buildQuery(?Run $personalRun, ?Registration $registration, array $slots): SessionQuery
    {
        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn(
            Session::create(self::SESSION_ID, self::EVENT_ID, new \DateTimeImmutable()),
        );

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findBySessionId')->willReturn($personalRun);

        $registrations = self::createStub(RegistrationRepositoryInterface::class);
        $registrations->method('findByEventAndUser')->willReturn($registration);

        $slotRepo = self::createStub(SessionSlotRepositoryInterface::class);
        $slotRepo->method('findByRegistrationAndSession')->willReturn($slots);

        return new SessionQuery(
            $sessions,
            self::createStub(ActiveRegistrationQueryInterface::class),
            $runs,
            self::createStub(RunParticipantRepositoryInterface::class),
            $registrations,
            $slotRepo,
        );
    }

    private function registration(string $id): Registration
    {
        $now = new \DateTimeImmutable();

        return new Registration($id, self::EVENT_ID, self::USER_ID, Registration::STATUS_RESERVED, $now, $now, []);
    }

    private function slot(string $registrationId, int $slotOrder): SessionSlot
    {
        return SessionSlot::create(
            'slot-'.$slotOrder,
            self::SESSION_ID,
            $registrationId,
            'game-'.$slotOrder,
            'Slot'.$slotOrder,
            $slotOrder,
        );
    }
}
