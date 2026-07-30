<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Message\NotifyGenerationFailureJob;
use App\Sessions\Application\Port\AchievementRecomputeTriggerInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Service\SessionLifecycleManager;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\WeeklyRuns\Domain\Repository\WeeklyEntryRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SessionLifecycleManagerRecordCrashTest extends TestCase
{
    private const string ABL_STDERR = <<<'LOG'
DEBUG worlds loaded: ["A Bug's Life"]
Exception: Too many upgrade items based on LEVEL_CAPS: 141 items for 16 locations. Disable some location categories/options or verify cap data.
Exception in <bound method BugsLifeWorld.create_items of <worlds.abugslife.BugsLifeWorld object at 0x7f>> for player 2, named masterkafey_ABL.
LOG;

    public function testRecordCrashAttributesFailureToNamedSlot(): void
    {
        $session = $this->generatingSession();
        $manager = $this->manager($session, [$this->slot('masterkafey_ABL')]);

        $result = $manager->recordCrash('session-1', self::ABL_STDERR);

        self::assertTrue($result['found']);
        self::assertSame(Session::STATUS_FAILED, $session->getStatus());
        self::assertSame([[
            'slotName' => 'masterkafey_ABL',
            'errors' => ['La génération a échoué à cause de ce slot : Exception: Too many upgrade items based on LEVEL_CAPS: 141 items for 16 locations. Disable some location categories/options or verify cap data.'],
        ]], $session->getValidationErrors());

        $logs = $session->getLastLogs();
        self::assertIsString($logs);
        self::assertStringNotContainsString('DEBUG', $logs);
        self::assertStringContainsString('Too many upgrade items', $logs);
    }

    public function testRecordCrashUnknownSlotFallsBackToGenericEntry(): void
    {
        $session = $this->generatingSession();
        $manager = $this->manager($session, [$this->slot('someone_else')]);

        $manager->recordCrash('session-1', self::ABL_STDERR);

        self::assertSame([[
            'slotName' => 'Génération',
            'errors' => ['La génération a échoué côté serveur : Exception: Too many upgrade items based on LEVEL_CAPS: 141 items for 16 locations. Disable some location categories/options or verify cap data.'],
        ]], $session->getValidationErrors());
    }

    public function testRecordCrashWithoutReasonKeepsGenericMessage(): void
    {
        $session = $this->generatingSession();
        $manager = $this->manager($session, [$this->slot('masterkafey_ABL')]);

        $manager->recordCrash('session-1', null);

        self::assertSame(Session::STATUS_FAILED, $session->getStatus());
        self::assertSame([[
            'slotName' => 'Génération',
            'errors' => ['La génération a échoué côté serveur. Vérifie ta configuration (YAML / jeux) puis relance.'],
        ]], $session->getValidationErrors());
        self::assertNull($session->getLastLogs());
    }

    public function testRecordCrashDispatchesNotificationJobWithFindings(): void
    {
        $session = $this->generatingSession();
        $dispatched = [];
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });
        $manager = $this->manager($session, [$this->slot('masterkafey_ABL')], $bus);

        $manager->recordCrash('session-1', self::ABL_STDERR);

        self::assertCount(1, $dispatched);
        $job = $dispatched[0];
        self::assertInstanceOf(NotifyGenerationFailureJob::class, $job);
        self::assertSame('session-1', $job->sessionId);
        self::assertSame([[
            'slotName' => 'masterkafey_ABL',
            'message' => 'Exception: Too many upgrade items based on LEVEL_CAPS: 141 items for 16 locations. Disable some location categories/options or verify cap data.',
        ]], $job->findings);
    }

    public function testRecordCrashDispatchesNotificationJobWithoutFindings(): void
    {
        $session = $this->generatingSession();
        $dispatched = [];
        $bus = self::createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });
        $manager = $this->manager($session, [$this->slot('masterkafey_ABL')], $bus);

        $manager->recordCrash('session-1', null);

        self::assertCount(1, $dispatched);
        $job = $dispatched[0];
        self::assertInstanceOf(NotifyGenerationFailureJob::class, $job);
        self::assertSame([], $job->findings);
    }

    private function generatingSession(): Session
    {
        $now = new \DateTimeImmutable('2026-07-30T12:00:00+00:00');
        $session = Session::create('session-1', 'event-1', $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);

        return $session;
    }

    private function slot(string $slotName): SessionSlot
    {
        return SessionSlot::create('slot-1', 'session-1', 'registration-1', 'game-1', $slotName, 0, null);
    }

    /**
     * @param list<SessionSlot> $slots
     */
    private function manager(Session $session, array $slots, ?MessageBusInterface $bus = null): SessionLifecycleManager
    {
        $sessions = self::createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        $slotRepository = self::createStub(SessionSlotRepositoryInterface::class);
        $slotRepository->method('findBySessionId')->willReturn($slots);

        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-07-30T12:34:56+00:00'));

        if (null === $bus) {
            $bus = self::createStub(MessageBusInterface::class);
            $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        }

        return new SessionLifecycleManager(
            sessions: $sessions,
            slots: $slotRepository,
            runs: self::createStub(RunRepositoryInterface::class),
            registrations: self::createStub(RegistrationRepositoryInterface::class),
            users: self::createStub(UserRepositoryInterface::class),
            events: self::createStub(EventRepositoryInterface::class),
            mercureHub: self::createStub(HubInterface::class),
            messageBus: $bus,
            logger: new NullLogger(),
            runnerGateway: self::createStub(RunnerGatewayInterface::class),
            weeklyEntries: self::createStub(WeeklyEntryRepositoryInterface::class),
            achievementRecomputeTrigger: self::createStub(AchievementRecomputeTriggerInterface::class),
            clock: $clock,
        );
    }
}
