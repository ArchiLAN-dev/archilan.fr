<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Handler\LaunchPersonalRunJobHandler;
use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Port\PersonalRunAdvancerInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Support\SlotNameGenerator;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Story 16.18: launching a run whose seed was generated somewhere else.
 *
 * The archive is a fait accompli: regenerating it would produce a different multiworld. So this
 * path builds the session's slots out of the archive's own slot table and never asks the
 * orchestrator to generate or configure anything.
 */
final class LaunchImportedSeedTest extends TestCase
{
    public function testItBuildsTheSessionFromTheArchiveWithoutGenerating(): void
    {
        $run = $this->importedRun([
            $this->slot(1, 'Alice_MC', 'Minecraft', 1, 'gs-1', ['user-alice', 'user-bob']),
            $this->slot(2, 'Bob_HK', 'Hollow Knight', 1, 'gs-2', []),
            // Not seats: the injected observer and an item-link group.
            $this->slot(3, 'Bridge', 'Archipelago', 0, 'gs-3', []),
            $this->slot(4, 'Links', 'Minecraft', 2, 'gs-4', []),
        ]);

        /** @var list<SessionSlot> $persistedSlots */
        $persistedSlots = [];
        $slots = self::createStub(SessionSlotRepositoryInterface::class);
        $slots->method('persist')->willReturnCallback(static function (SessionSlot $slot) use (&$persistedSlots): void {
            $persistedSlots[] = $slot;
        });

        /** @var list<SlotCoPlayer> $persistedCoPlayers */
        $persistedCoPlayers = [];
        $coPlayers = self::createStub(SlotCoPlayerRepositoryInterface::class);
        $coPlayers->method('persist')->willReturnCallback(static function (SlotCoPlayer $coPlayer) use (&$persistedCoPlayers): void {
            $persistedCoPlayers[] = $coPlayer;
        });

        /** @var Session|null $persistedSession */
        $persistedSession = null;
        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('persist')->willReturnCallback(static function (Session $session) use (&$persistedSession): void {
            $persistedSession = $session;
        });

        // Generating or configuring an imported seed would be a bug, not a fallback.
        $runner = $this->createMock(RunnerGatewayInterface::class);
        $runner->expects(self::never())->method('configureSession');
        $runner->expects(self::never())->method('generateSession');

        $advancer = $this->createMock(PersonalRunAdvancerInterface::class);
        $advancer->expects(self::once())->method('autoAdvancePersonalRun');

        $this->handler($run, $sessions, $slots, $coPlayers, $runner, $advancer)(new LaunchPersonalRunJob($run->getId()));

        // Only the two player slots became seats.
        self::assertCount(2, $persistedSlots);
        self::assertSame(['Alice_MC', 'Bob_HK'], array_map(static fn (SessionSlot $s): string => $s->getSlotName(), $persistedSlots));

        // The first assignee owns their slot; the second plays it without owning it (story 16.17).
        self::assertSame('user-alice', $persistedSlots[0]->getRegistrationId());
        self::assertCount(1, $persistedCoPlayers);
        self::assertSame('gs-1', $persistedCoPlayers[0]->getSlotId());
        self::assertSame('user-bob', $persistedCoPlayers[0]->getUserId());

        // An unassigned slot has no owner at all rather than a wrong one.
        self::assertSame('', $persistedSlots[1]->getRegistrationId());

        // The archive is the output, and the session is ready to be launched on it.
        self::assertInstanceOf(Session::class, $persistedSession);
        self::assertSame(Session::STATUS_GENERATED, $persistedSession->getStatus());
        self::assertSame('run-1/imported/AP_1.zip', $persistedSession->getGeneratedOutputKey());
    }

    /** The game slot id is minted at import and reused at every launch, so co-players survive it. */
    public function testTheSlotKeepsItsImportedGameSlotId(): void
    {
        $run = $this->importedRun([$this->slot(1, 'Alice_MC', 'Minecraft', 1, 'gs-stable', ['user-alice'])]);

        /** @var list<SessionSlot> $persistedSlots */
        $persistedSlots = [];
        $slots = self::createStub(SessionSlotRepositoryInterface::class);
        $slots->method('persist')->willReturnCallback(static function (SessionSlot $slot) use (&$persistedSlots): void {
            $persistedSlots[] = $slot;
        });

        $this->handler($run, self::createStub(SessionRepositoryInterface::class), $slots)(new LaunchPersonalRunJob($run->getId()));

        self::assertSame('gs-stable', $persistedSlots[0]->getSlotId());
    }

    public function testAnImportWithoutSlotsIsRefusedRatherThanLaunched(): void
    {
        $run = Run::create('user-alice', 'Ma run', new \DateTimeImmutable('2026-08-26T10:00:00+00:00'));

        $advancer = $this->createMock(PersonalRunAdvancerInterface::class);
        $advancer->expects(self::never())->method('autoAdvancePersonalRun');

        $slots = $this->createMock(SessionSlotRepositoryInterface::class);
        $slots->expects(self::never())->method('persist');

        $run->importSeed('run-1/imported/AP_1.zip', [], new \DateTimeImmutable('2026-08-26T10:00:00+00:00'));

        $this->handler($run, self::createStub(SessionRepositoryInterface::class), $slots, advancer: $advancer)(new LaunchPersonalRunJob($run->getId()));
    }

    /**
     * @param list<array{slot: int, name: string, game: string, type: int, slotId: string, assignedUserIds: list<string>}> $slots
     */
    private function importedRun(array $slots): Run
    {
        $now = new \DateTimeImmutable('2026-08-26T10:00:00+00:00');
        $run = Run::create('user-alice', 'Ma run', $now);
        $run->importSeed('run-1/imported/AP_1.zip', $slots, $now);

        return $run;
    }

    /**
     * @param list<string> $assigned
     *
     * @return array{slot: int, name: string, game: string, type: int, slotId: string, assignedUserIds: list<string>}
     */
    private function slot(int $number, string $name, string $game, int $type, string $slotId, array $assigned): array
    {
        return [
            'slot' => $number,
            'name' => $name,
            'game' => $game,
            'type' => $type,
            'slotId' => $slotId,
            'assignedUserIds' => $assigned,
        ];
    }

    private function handler(
        Run $run,
        SessionRepositoryInterface $sessions,
        SessionSlotRepositoryInterface $slots,
        ?SlotCoPlayerRepositoryInterface $coPlayers = null,
        ?RunnerGatewayInterface $runner = null,
        ?PersonalRunAdvancerInterface $advancer = null,
    ): LaunchPersonalRunJobHandler {
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        return new LaunchPersonalRunJobHandler(
            $runs,
            self::createStub(RunParticipantRepositoryInterface::class),
            self::createStub(UserRepositoryInterface::class),
            self::createStub(GameRepositoryInterface::class),
            $sessions,
            $slots,
            new SlotNameGenerator(),
            $runner ?? self::createStub(RunnerGatewayInterface::class),
            $advancer ?? self::createStub(PersonalRunAdvancerInterface::class),
            self::createStub(LoggerInterface::class),
            new MockClock(),
            $coPlayers ?? self::createStub(SlotCoPlayerRepositoryInterface::class),
        );
    }
}
