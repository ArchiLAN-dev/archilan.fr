<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Community\Application\Support\Notifier;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Handler\NotifySlotUnblockedJobHandler;
use App\Sessions\Application\Message\NotifySlotUnblockedJob;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Story 40.1. The player of the unblocked slot and its co-players are told, once each.
 */
final class NotifySlotUnblockedJobHandlerTest extends TestCase
{
    private SpySlotUnblockedNotifier $notifier;

    public function testThePlayerAndTheCoPlayersAreNotifiedOnceEach(): void
    {
        $run = Run::create('owner-1', 'Ma run', new \DateTimeImmutable('2026-09-29T09:00:00+00:00'));
        $slot = SessionSlot::create('slot-1', 'session-1', 'user-1', 'game-1', 'Alice_HK1', 0, 'game-slot-1');
        $now = new \DateTimeImmutable('2026-09-29T09:00:00+00:00');
        $handler = $this->handler($run, $slot, [
            SlotCoPlayer::create('co-1', 'game-slot-1', 'user-2', $now),
            SlotCoPlayer::create('co-2', 'game-slot-1', 'user-1', $now),
        ]);

        $handler(new NotifySlotUnblockedJob('session-1', 'Alice_HK1', 3));

        self::assertSame(['user-1', 'user-2'], array_column($this->notifier->calls, 'recipientId'));
        $call = $this->notifier->calls[0];
        self::assertSame('slot_unblocked', $call['type']);
        self::assertSame($run->getId(), $call['payload']['runId']);
        self::assertSame('Ma run', $call['payload']['runTitle']);
        self::assertSame('Alice_HK1', $call['payload']['slotName']);
        self::assertSame(3, $call['payload']['reachableNow']);
    }

    public function testNoRunOrNoSlotNotifiesNobody(): void
    {
        $slot = SessionSlot::create('slot-1', 'session-1', 'user-1', 'game-1', 'Alice_HK1', 0, 'game-slot-1');

        ($this->handler(null, $slot, []))(new NotifySlotUnblockedJob('session-1', 'Alice_HK1', 3));
        $run = Run::create('owner-1', 'Ma run', new \DateTimeImmutable('2026-09-29T09:00:00+00:00'));
        ($this->handler($run, null, []))(new NotifySlotUnblockedJob('session-1', 'Alice_HK1', 3));

        self::assertSame([], $this->notifier->calls);
    }

    /**
     * @param list<SlotCoPlayer> $coPlayers
     */
    private function handler(?Run $run, ?SessionSlot $slot, array $coPlayers): NotifySlotUnblockedJobHandler
    {
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findBySessionId')->willReturn($run);

        $slots = self::createStub(SessionSlotRepositoryInterface::class);
        $slots->method('findBySessionAndSlotName')->willReturn($slot);

        $coPlayerRepository = self::createStub(SlotCoPlayerRepositoryInterface::class);
        $coPlayerRepository->method('findBySlotIds')->willReturn($coPlayers);

        $this->notifier = new SpySlotUnblockedNotifier();

        return new NotifySlotUnblockedJobHandler($runs, $slots, $coPlayerRepository, $this->notifier, new NullLogger());
    }
}

final class SpySlotUnblockedNotifier implements Notifier
{
    /** @var list<array{recipientId: string, type: string, payload: array<string, mixed>}> */
    public array $calls = [];

    public function notify(string $recipientId, string $type, array $payload): void
    {
        $this->calls[] = ['recipientId' => $recipientId, 'type' => $type, 'payload' => $payload];
    }
}
