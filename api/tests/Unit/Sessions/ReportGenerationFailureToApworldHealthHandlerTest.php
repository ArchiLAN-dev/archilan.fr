<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\GameSelection\Application\Message\ReportDefaultYamlFailureJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Handler\ReportGenerationFailureToApworldHealthHandler;
use App\Sessions\Application\Message\NotifyGenerationFailureJob;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 38.4: a real generation failure attributed to a slot is sent to the apworld health with the
 * slot's YAML and apworld, so an apworld failing with its default YAML opens an incident.
 */
final class ReportGenerationFailureToApworldHealthHandlerTest extends TestCase
{
    private const string YAML = "name: Jean\ngame: Crystal Project\nCrystal Project:\n  goal: astley\n";

    /** @var list<object> */
    private array $dispatched = [];

    public function testAnAttributedSlotOfAPersonalRunIsReportedWithItsYamlAndHash(): void
    {
        $run = Run::create('owner-1', 'Soirée', new \DateTimeImmutable('2026-09-20'));
        $run->attachSession('session-1');
        $participant = RunParticipant::create($run->getId(), 'user-1', new \DateTimeImmutable('2026-09-20'));
        $participant->replaceSlots([['slotId' => 'slot-1', 'gameId' => 'game-1', 'playerYaml' => self::YAML, 'apworldHash' => 'hash-1']]);

        $this->handle(
            [SessionSlot::create('ss-1', 'session-1', 'user-1', 'game-1', 'Jean', 0, 'slot-1')],
            [['slotName' => 'Jean', 'message' => 'Fill.FillError: no location left']],
            run: $run,
            participant: $participant,
        );

        self::assertEquals([new ReportDefaultYamlFailureJob('game-1', 'hash-1', self::YAML, 'Fill.FillError: no location left')], $this->dispatched);
    }

    public function testAnAttributedSlotOfAnEventIsReportedOnTheServedApworld(): void
    {
        // An event generates every slot with the apworld its game serves: no hash of its own.
        $now = new \DateTimeImmutable('2026-09-20');
        $registration = new Registration('reg-1', 'event-1', 'user-1', Registration::STATUS_RESERVED, $now, $now, [
            ['slotId' => 'slot-1', 'gameId' => 'game-1', 'slotOrder' => 1, 'apworldHash' => 'hash-0', 'playerYaml' => null],
        ]);

        $this->handle(
            [SessionSlot::create('ss-1', 'session-1', 'reg-1', 'game-1', 'Jean', 0, 'slot-1')],
            [['slotName' => 'Jean', 'message' => 'boom']],
            registration: $registration,
        );

        self::assertEquals([new ReportDefaultYamlFailureJob('game-1', null, '', 'boom')], $this->dispatched);
    }

    public function testAnEventSlotIsReportedOnTheApworldServedAtTheCrash(): void
    {
        // Story 38.4 review: a promotion between the crash and this job must not take the blame.
        $now = new \DateTimeImmutable('2026-09-20');
        $registration = new Registration('reg-1', 'event-1', 'user-1', Registration::STATUS_RESERVED, $now, $now, [
            ['slotId' => 'slot-1', 'gameId' => 'game-1', 'slotOrder' => 1, 'playerYaml' => null],
        ]);

        $this->handle(
            [SessionSlot::create('ss-1', 'session-1', 'reg-1', 'game-1', 'Jean', 0, 'slot-1')],
            [['slotName' => 'Jean', 'message' => 'boom']],
            registration: $registration,
            servedAtCrash: ['game-1' => 'hash-at-crash'],
        );

        self::assertEquals([new ReportDefaultYamlFailureJob('game-1', 'hash-at-crash', '', 'boom')], $this->dispatched);
    }

    public function testAnUnattributedFailureReportsNothing(): void
    {
        $this->handle(
            [SessionSlot::create('ss-1', 'session-1', 'user-1', 'game-1', 'Jean', 0, 'slot-1')],
            [['slotName' => null, 'message' => 'boom'], ['slotName' => 'Nobody', 'message' => 'boom']],
        );

        self::assertSame([], $this->dispatched);
    }

    public function testASlotIsReportedOnceWhateverItsNumberOfFindings(): void
    {
        $run = Run::create('owner-1', 'Soirée', new \DateTimeImmutable('2026-09-20'));
        $run->attachSession('session-1');
        $participant = RunParticipant::create($run->getId(), 'user-1', new \DateTimeImmutable('2026-09-20'));
        $participant->replaceSlots([['slotId' => 'slot-1', 'gameId' => 'game-1', 'playerYaml' => self::YAML, 'apworldHash' => 'hash-1']]);

        $this->handle(
            [SessionSlot::create('ss-1', 'session-1', 'user-1', 'game-1', 'Jean', 0, 'slot-1')],
            [['slotName' => 'Jean', 'message' => 'first'], ['slotName' => 'Jean', 'message' => 'second']],
            run: $run,
            participant: $participant,
        );

        self::assertCount(1, $this->dispatched);
    }

    public function testASlotOfAnImportedArchiveReportsNothing(): void
    {
        // Story 16.18: an imported archive's slot has no game of ours.
        $run = Run::create('owner-1', 'Archive', new \DateTimeImmutable('2026-09-20'));
        $run->attachSession('session-1');

        $this->handle(
            [SessionSlot::create('ss-1', 'session-1', 'user-1', '', 'Jean', 0, 'slot-1')],
            [['slotName' => 'Jean', 'message' => 'boom']],
            run: $run,
        );

        self::assertSame([], $this->dispatched);
    }

    /**
     * @param list<SessionSlot>                                   $slots
     * @param list<array{slotName: string|null, message: string}> $findings
     * @param array<string, string>                               $servedAtCrash
     */
    private function handle(array $slots, array $findings, ?Run $run = null, ?RunParticipant $participant = null, ?Registration $registration = null, array $servedAtCrash = []): void
    {
        $sessionSlots = self::createStub(SessionSlotRepositoryInterface::class);
        $sessionSlots->method('findBySessionId')->willReturn($slots);
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findBySessionId')->willReturn($run);
        $participants = self::createStub(RunParticipantRepositoryInterface::class);
        $participants->method('findByRunAndUser')->willReturn($participant);
        $registrations = self::createStub(RegistrationRepositoryInterface::class);
        $registrations->method('findById')->willReturn($registration);
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        new ReportGenerationFailureToApworldHealthHandler($sessionSlots, $runs, $participants, $registrations, $bus, new NullLogger())(
            new NotifyGenerationFailureJob('session-1', $findings, $servedAtCrash),
        );
    }
}
