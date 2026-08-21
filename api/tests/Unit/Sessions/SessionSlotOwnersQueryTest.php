<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Query\SessionSlotOwnersQuery;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Names the member behind each Archipelago slot, so the goal celebration stops labelling a slot with
 * the pseudo of whoever happens to be watching it.
 *
 * The two session kinds key their slots differently: an event session stores the registration id in
 * SessionSlot.registrationId, a personal-run session stores the participant userId directly
 * (LaunchPersonalRunJobHandler).
 */
final class SessionSlotOwnersQueryTest extends TestCase
{
    private const string SESSION_ID = 'sess-1';

    public function testPersonalRunSessionNamesEachSlotByItsParticipant(): void
    {
        $query = $this->buildQuery(
            personalRun: true,
            slots: [$this->slot('user-alice', 'Alice_TWW'), $this->slot('user-bob', 'Bob_HK')],
            registrations: [],
            names: ['user-alice' => 'Alice', 'user-bob' => 'Bob'],
        );

        self::assertSame([
            ['slotName' => 'Alice_TWW', 'playerName' => 'Alice'],
            ['slotName' => 'Bob_HK', 'playerName' => 'Bob'],
        ], $query->execute(self::SESSION_ID));
    }

    public function testEventSessionResolvesTheRegistrationToItsUser(): void
    {
        $query = $this->buildQuery(
            personalRun: false,
            slots: [$this->slot('reg-alice', 'Alice_TWW'), $this->slot('reg-bob', 'Bob_HK')],
            registrations: [
                $this->registration('reg-alice', 'user-alice'),
                $this->registration('reg-bob', 'user-bob'),
            ],
            names: ['user-alice' => 'Alice', 'user-bob' => 'Bob'],
        );

        self::assertSame([
            ['slotName' => 'Alice_TWW', 'playerName' => 'Alice'],
            ['slotName' => 'Bob_HK', 'playerName' => 'Bob'],
        ], $query->execute(self::SESSION_ID));
    }

    /**
     * A player who set `name:` in their yaml keeps that slot name, which says nothing about who they
     * are - the very reason the celebration cannot fall back on it to name a player.
     */
    public function testACustomSlotNameStillResolvesToItsOwner(): void
    {
        $query = $this->buildQuery(
            personalRun: true,
            slots: [$this->slot('user-alice', 'TotallyOther')],
            registrations: [],
            names: ['user-alice' => 'Alice'],
        );

        self::assertSame([['slotName' => 'TotallyOther', 'playerName' => 'Alice']], $query->execute(self::SESSION_ID));
    }

    /** A deleted account drops out of namesFor(): the slot is still listed, just unnamed. */
    public function testUnresolvedOwnerYieldsAnEmptyName(): void
    {
        $query = $this->buildQuery(
            personalRun: true,
            slots: [$this->slot('user-ghost', 'Ghost_TWW')],
            registrations: [],
            names: [],
        );

        self::assertSame([['slotName' => 'Ghost_TWW', 'playerName' => '']], $query->execute(self::SESSION_ID));
    }

    public function testSessionWithoutSlotsIsEmpty(): void
    {
        $query = $this->buildQuery(personalRun: true, slots: [], registrations: [], names: []);

        self::assertSame([], $query->execute(self::SESSION_ID));
    }

    /**
     * @param list<SessionSlot>     $slots
     * @param list<Registration>    $registrations
     * @param array<string, string> $names
     */
    private function buildQuery(bool $personalRun, array $slots, array $registrations, array $names): SessionSlotOwnersQuery
    {
        $slotRepo = self::createStub(SessionSlotRepositoryInterface::class);
        $slotRepo->method('findBySessionId')->willReturn($slots);

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findBySessionId')->willReturn(
            $personalRun ? Run::create('user-alice', 'Run', new \DateTimeImmutable()) : null,
        );

        $registrationRepo = self::createStub(RegistrationRepositoryInterface::class);
        $registrationRepo->method('findBy')->willReturn($registrations);

        $directory = self::createStub(CommunityUserDirectoryQueryInterface::class);
        $directory->method('namesFor')->willReturn($names);

        return new SessionSlotOwnersQuery($slotRepo, $runs, $registrationRepo, $directory);
    }

    private function registration(string $id, string $userId): Registration
    {
        $now = new \DateTimeImmutable();

        return new Registration($id, 'evt-1', $userId, Registration::STATUS_RESERVED, $now, $now, []);
    }

    private function slot(string $ownerKey, string $slotName): SessionSlot
    {
        return SessionSlot::create(
            'slot-'.$slotName,
            self::SESSION_ID,
            $ownerKey,
            'game-'.$slotName,
            $slotName,
            1,
        );
    }
}
