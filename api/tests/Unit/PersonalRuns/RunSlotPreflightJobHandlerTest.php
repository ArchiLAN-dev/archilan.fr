<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Handler\RunSlotPreflightJobHandler;
use App\PersonalRuns\Application\Message\RunSlotPreflightJob;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class RunSlotPreflightJobHandlerTest extends TestCase
{
    private const string YAML = "name: Jean\ngame: TUNIC\n";

    /** @var list<Envelope> */
    private array $dispatched = [];

    public function testStartQueuesOrchestratorJobAndRedispatchesDelayed(): void
    {
        $participant = $this->participant();
        $handler = $this->handler($participant, startResult: 'orch-1');

        $handler(new RunSlotPreflightJob('run-1', 'user-1', 'slot-1', $this->sha()));

        self::assertCount(1, $this->dispatched);
        $envelope = $this->dispatched[0];
        $message = $envelope->getMessage();
        self::assertInstanceOf(RunSlotPreflightJob::class, $message);
        self::assertSame('orch-1', $message->orchestratorJobId);
        self::assertNotNull($envelope->last(DelayStamp::class));
    }

    public function testStartWithRunnerDownRecordsFailedVerdict(): void
    {
        $participant = $this->participant();
        $handler = $this->handler($participant, startResult: null);

        $handler(new RunSlotPreflightJob('run-1', 'user-1', 'slot-1', $this->sha()));

        $slot = $participant->getSlot('slot-1');
        self::assertNotNull($slot);
        $preflight = $slot['preflight'] ?? null;
        self::assertNotNull($preflight);
        self::assertSame('failed', $preflight['status']);
        self::assertSame([], $this->dispatched);
    }

    public function testPollSettledFailureRecordsParsedMessage(): void
    {
        $participant = $this->participant();
        $handler = $this->handler($participant, pollResult: [
            'status' => 'failed',
            'error' => "Traceback (most recent call last):\n  File \"Fill.py\", line 1\nFillError: No more spots to place items.",
        ]);

        $handler(new RunSlotPreflightJob('run-1', 'user-1', 'slot-1', $this->sha(), 'orch-1', 3));

        $slot = $participant->getSlot('slot-1');
        self::assertNotNull($slot);
        $preflight = $slot['preflight'] ?? null;
        self::assertNotNull($preflight);
        self::assertSame('failed', $preflight['status']);
        self::assertSame('FillError: No more spots to place items.', $preflight['error']);
    }

    public function testPollPassedRecordsVerdict(): void
    {
        $participant = $this->participant();
        $handler = $this->handler($participant, pollResult: ['status' => 'passed', 'error' => '']);

        $handler(new RunSlotPreflightJob('run-1', 'user-1', 'slot-1', $this->sha(), 'orch-1', 0));

        $slot = $participant->getSlot('slot-1');
        self::assertNotNull($slot);
        $preflight = $slot['preflight'] ?? null;
        self::assertNotNull($preflight);
        self::assertSame('passed', $preflight['status']);
    }

    public function testPollPendingRedispatchesWithIncrementedCounter(): void
    {
        $participant = $this->participant();
        $handler = $this->handler($participant, pollResult: ['status' => 'pending', 'error' => '']);

        $handler(new RunSlotPreflightJob('run-1', 'user-1', 'slot-1', $this->sha(), 'orch-1', 4));

        self::assertCount(1, $this->dispatched);
        $message = $this->dispatched[0]->getMessage();
        self::assertInstanceOf(RunSlotPreflightJob::class, $message);
        self::assertSame(5, $message->polls);
    }

    public function testStaleYamlShaDropsTheJobSilently(): void
    {
        $participant = $this->participant();
        $handler = $this->handler($participant, pollResult: ['status' => 'passed', 'error' => '']);

        $handler(new RunSlotPreflightJob('run-1', 'user-1', 'slot-1', 'stale-sha', 'orch-1', 0));

        $slot = $participant->getSlot('slot-1');
        self::assertNotNull($slot);
        self::assertArrayNotHasKey('preflight', $slot);
        self::assertSame([], $this->dispatched);
    }

    private function sha(): string
    {
        return hash('sha256', self::YAML);
    }

    private function participant(): RunParticipant
    {
        $participant = RunParticipant::create('run-1', 'user-1', new \DateTimeImmutable('2026-07-30T10:00:00+00:00'));
        $participant->replaceSlots([['slotId' => 'slot-1', 'gameId' => 'game-1']]);
        $participant->submitSlotPlayerYaml('slot-1', self::YAML, 'hash-1');

        return $participant;
    }

    /**
     * @param array{status: string, error: string}|null $pollResult
     */
    private function handler(RunParticipant $participant, ?string $startResult = null, ?array $pollResult = null): RunSlotPreflightJobHandler
    {
        $participants = self::createStub(RunParticipantRepositoryInterface::class);
        $participants->method('findByRunAndUser')->willReturn($participant);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('startSlotPreflight')->willReturn($startResult);
        $runner->method('getSlotPreflight')->willReturn($pollResult);

        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            $onlyStamps = array_values(array_filter($stamps, static fn (mixed $stamp): bool => $stamp instanceof StampInterface));
            $envelope = new Envelope($message, $onlyStamps);
            $this->dispatched[] = $envelope;

            return $envelope;
        });

        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-30T12:00:00+00:00'));

        return new RunSlotPreflightJobHandler(
            participants: $participants,
            runnerGateway: $runner,
            messageBus: $bus,
            clock: $clock,
            logger: new NullLogger(),
        );
    }
}
