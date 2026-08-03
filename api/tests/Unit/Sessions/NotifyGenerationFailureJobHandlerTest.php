<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Community\Application\Support\Notifier;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Handler\NotifyGenerationFailureJobHandler;
use App\Sessions\Application\Message\NotifyGenerationFailureJob;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NotifyGenerationFailureJobHandlerTest extends TestCase
{
    private SpyGenerationFailureNotifier $notifier;

    /** @return list<array{recipientId: string, type: string, payload: array<string, mixed>}> */
    private function notified(): array
    {
        return $this->notifier->calls;
    }

    public function testPersonalRunNotifiesFaultyPlayerAndOwner(): void
    {
        $run = Run::create('owner-1', 'Run du samedi', new \DateTimeImmutable('2026-07-30T10:00:00+00:00'));
        $handler = $this->handler($run, [$this->slot('masterkafey_ABL', 'user-2')]);

        $handler(new NotifyGenerationFailureJob('session-1', [
            ['slotName' => 'masterkafey_ABL', 'message' => 'Exception: Too many upgrade items.'],
        ]));

        self::assertCount(2, $this->notified());

        self::assertSame('user-2', $this->notified()[0]['recipientId']);
        self::assertSame('generation_failed', $this->notified()[0]['type']);
        self::assertSame('player', $this->notified()[0]['payload']['role']);
        self::assertSame('masterkafey_ABL', $this->notified()[0]['payload']['slotName']);
        self::assertSame('Exception: Too many upgrade items.', $this->notified()[0]['payload']['message']);
        self::assertSame($run->getId(), $this->notified()[0]['payload']['runId']);
        self::assertSame('Run du samedi', $this->notified()[0]['payload']['runTitle']);

        self::assertSame('owner-1', $this->notified()[1]['recipientId']);
        self::assertSame('owner', $this->notified()[1]['payload']['role']);
        self::assertSame(['masterkafey_ABL'], $this->notified()[1]['payload']['slotNames']);
    }

    public function testOwnerWhoIsTheFaultyPlayerGetsOnlyThePlayerNotification(): void
    {
        $run = Run::create('user-2', 'Ma run', new \DateTimeImmutable('2026-07-30T10:00:00+00:00'));
        $handler = $this->handler($run, [$this->slot('masterkafey_ABL', 'user-2')]);

        $handler(new NotifyGenerationFailureJob('session-1', [
            ['slotName' => 'masterkafey_ABL', 'message' => 'Exception: broken.'],
        ]));

        self::assertCount(1, $this->notified());
        self::assertSame('user-2', $this->notified()[0]['recipientId']);
        self::assertSame('player', $this->notified()[0]['payload']['role']);
    }

    public function testUnattributedFailureNotifiesOnlyTheOwnerWithEmptySlotList(): void
    {
        $run = Run::create('owner-1', 'Ma run', new \DateTimeImmutable('2026-07-30T10:00:00+00:00'));
        $handler = $this->handler($run, [$this->slot('masterkafey_ABL', 'user-2')]);

        $handler(new NotifyGenerationFailureJob('session-1', [
            ['slotName' => null, 'message' => 'FillError: No more spots to place items.'],
        ]));

        self::assertCount(1, $this->notified());
        self::assertSame('owner-1', $this->notified()[0]['recipientId']);
        self::assertSame('owner', $this->notified()[0]['payload']['role']);
        self::assertSame([], $this->notified()[0]['payload']['slotNames']);
    }

    public function testEventSessionResolvesPlayerThroughRegistration(): void
    {
        $registration = new Registration(
            'reg-1',
            'event-1',
            'user-9',
            Registration::STATUS_RESERVED,
            new \DateTimeImmutable('2026-07-30T10:00:00+00:00'),
            new \DateTimeImmutable('2026-07-30T10:00:00+00:00'),
        );
        $handler = $this->handler(null, [$this->slot('joueur_HK', 'reg-1')], $registration);

        $handler(new NotifyGenerationFailureJob('session-1', [
            ['slotName' => 'joueur_HK', 'message' => 'Exception: bad options.'],
        ]));

        self::assertCount(1, $this->notified());
        self::assertSame('user-9', $this->notified()[0]['recipientId']);
        self::assertSame('player', $this->notified()[0]['payload']['role']);
        self::assertNull($this->notified()[0]['payload']['runId']);
    }

    public function testUnknownSlotNameIsIgnoredForPlayersButOwnerIsStillNotified(): void
    {
        $run = Run::create('owner-1', 'Ma run', new \DateTimeImmutable('2026-07-30T10:00:00+00:00'));
        $handler = $this->handler($run, [$this->slot('masterkafey_ABL', 'user-2')]);

        $handler(new NotifyGenerationFailureJob('session-1', [
            ['slotName' => 'slot_inconnu', 'message' => 'Exception: broken.'],
        ]));

        self::assertCount(1, $this->notified());
        self::assertSame('owner-1', $this->notified()[0]['recipientId']);
        self::assertSame([], $this->notified()[0]['payload']['slotNames']);
    }

    private function slot(string $slotName, string $registrationId): SessionSlot
    {
        return SessionSlot::create('slot-1', 'session-1', $registrationId, 'game-1', $slotName, 0, null);
    }

    /**
     * @param list<SessionSlot> $slots
     */
    private function handler(?Run $run, array $slots, ?Registration $registration = null): NotifyGenerationFailureJobHandler
    {
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findBySessionId')->willReturn($run);

        $slotRepository = self::createStub(SessionSlotRepositoryInterface::class);
        $slotRepository->method('findBySessionId')->willReturn($slots);

        $registrations = self::createStub(RegistrationRepositoryInterface::class);
        $registrations->method('findById')->willReturn($registration);

        $this->notifier = new SpyGenerationFailureNotifier();

        return new NotifyGenerationFailureJobHandler(
            slots: $slotRepository,
            runs: $runs,
            registrations: $registrations,
            notifier: $this->notifier,
            logger: new NullLogger(),
        );
    }
}

final class SpyGenerationFailureNotifier implements Notifier
{
    /** @var list<array{recipientId: string, type: string, payload: array<string, mixed>}> */
    public array $calls = [];

    public function notify(string $recipientId, string $type, array $payload): void
    {
        $this->calls[] = ['recipientId' => $recipientId, 'type' => $type, 'payload' => $payload];
    }
}
