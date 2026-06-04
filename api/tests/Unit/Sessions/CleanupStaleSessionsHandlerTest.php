<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\PersonalRunAdvancerInterface;
use App\Sessions\Application\ScheduledTask\CleanupStaleSessionsHandler;
use App\Sessions\Application\ScheduledTask\CleanupStaleSessionsTask;
use App\Sessions\Application\SessionReconcilerInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Application\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;

final class CleanupStaleSessionsHandlerTest extends TestCase
{
    public function testGeneratingSessionReconciledToGeneratedWhenOrchestateurConfirms(): void
    {
        $session = $this->makeStaleSession(Session::STATUS_GENERATING);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findByStatuses')->willReturn([$session]);

        $lifecycleManager = $this->createMock(SessionReconcilerInterface::class);
        $lifecycleManager->expects($this->once())
            ->method('transition')
            ->with($session->getId(), Session::STATUS_GENERATED);

        $gateway = $this->createStub(RunnerGatewayInterface::class);
        $gateway->method('getSessionInfo')->willReturn(['status' => 'generated', 'bridgePort' => null]);

        $this->makeHandler($sessions, $lifecycleManager, $gateway)(new CleanupStaleSessionsTask());
    }

    public function testLaunchingSessionReconciledToRunningWhenOrchestateurConfirms(): void
    {
        $session = $this->makeStaleSession(Session::STATUS_LAUNCHING);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findByStatuses')->willReturn([$session]);

        $lifecycleManager = $this->createMock(SessionReconcilerInterface::class);
        $lifecycleManager->expects($this->once())
            ->method('transitionToRunningFromOrchestrateur')
            ->with($session->getId(), 48281, 38281);

        $gateway = $this->createStub(RunnerGatewayInterface::class);
        $gateway->method('getSessionInfo')->willReturn(['status' => 'running', 'bridgePort' => 38281, 'apPort' => 48281]);

        $this->makeHandler($sessions, $lifecycleManager, $gateway)(new CleanupStaleSessionsTask());
    }

    public function testLaunchingSessionNotReconciledWhenBridgePortMissing(): void
    {
        $session = $this->makeStaleSession(Session::STATUS_LAUNCHING);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findByStatuses')->willReturn([$session]);
        $sessions->method('flush');

        $reconciler = $this->createMock(SessionReconcilerInterface::class);
        $reconciler->expects($this->never())->method('transitionToRunningFromOrchestrateur');

        $gateway = $this->createStub(RunnerGatewayInterface::class);
        $gateway->method('getSessionInfo')->willReturn(['status' => 'running', 'bridgePort' => null, 'apPort' => null]);

        $hub = $this->createStub(HubInterface::class);

        $this->makeHandler($sessions, $reconciler, $gateway, $hub)(new CleanupStaleSessionsTask());

        self::assertSame(Session::STATUS_FAILED, $session->getStatus());
    }

    public function testGeneratingSessionMarkedFailedWhenOrchestateurUnknown(): void
    {
        $session = $this->makeStaleSession(Session::STATUS_GENERATING);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findByStatuses')->willReturn([$session]);
        $sessions->method('flush');

        $reconciler = $this->createMock(SessionReconcilerInterface::class);
        $reconciler->expects($this->never())->method('transition');

        $gateway = $this->createStub(RunnerGatewayInterface::class);
        $gateway->method('getSessionInfo')->willReturn(null);

        $hub = $this->createStub(HubInterface::class);

        $this->makeHandler($sessions, $reconciler, $gateway, $hub)(new CleanupStaleSessionsTask());

        self::assertSame(Session::STATUS_FAILED, $session->getStatus());
    }

    public function testRunningSessionMarkedCrashedWithoutOrchestateurQuery(): void
    {
        // Orchestrateur-managed session (runnerId = null): stopSession must NOT be called.
        $session = $this->makeStaleSession(Session::STATUS_RUNNING);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findByStatuses')->willReturn([$session]);
        $sessions->method('flush');

        $reconciler = $this->createMock(SessionReconcilerInterface::class);
        $reconciler->expects($this->never())->method('transition');
        $reconciler->expects($this->never())->method('transitionToRunningFromOrchestrateur');

        $gateway = $this->createMock(RunnerGatewayInterface::class);
        $gateway->expects($this->never())->method('getSessionInfo');
        $gateway->expects($this->never())->method('stopSession');

        $advancer = $this->createMock(PersonalRunAdvancerInterface::class);
        $advancer->expects($this->once())->method('markPersonalRunStopped')->with($session->getId());

        $hub = $this->createStub(HubInterface::class);

        $this->makeHandler($sessions, $reconciler, $gateway, $hub, $advancer)(new CleanupStaleSessionsTask());

        self::assertSame(Session::STATUS_CRASHED, $session->getStatus());
    }

    public function testRunningRunnerSessionCallsStopOnCrash(): void
    {
        // Runner-managed session (runnerId set): stopSession must be called.
        $session = $this->makeStaleSession(Session::STATUS_RUNNING);
        $past = new \DateTimeImmutable('-30 minutes');
        $session->lockTo('runner-1', $past);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findByStatuses')->willReturn([$session]);
        $sessions->method('flush');

        $reconciler = $this->createStub(SessionReconcilerInterface::class);

        $gateway = $this->createMock(RunnerGatewayInterface::class);
        $gateway->expects($this->never())->method('getSessionInfo');
        $gateway->expects($this->once())->method('stopSession')->with($session->getId());

        $advancer = $this->createMock(PersonalRunAdvancerInterface::class);
        $advancer->expects($this->once())->method('markPersonalRunStopped')->with($session->getId());

        $hub = $this->createStub(HubInterface::class);

        $this->makeHandler($sessions, $reconciler, $gateway, $hub, $advancer)(new CleanupStaleSessionsTask());

        self::assertSame(Session::STATUS_CRASHED, $session->getStatus());
    }

    private function makeStaleSession(string $targetStatus): Session
    {
        $past = new \DateTimeImmutable('-30 minutes');
        $session = Session::create('sess-test', 'evt-001', $past);

        $path = [
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
            Session::STATUS_RUNNING,
        ];

        foreach ($path as $step) {
            if (Session::STATUS_RUNNING === $step) {
                $session->transition($step, $past, 'bridge.local', 38281, 'secret');
            } else {
                $session->transition($step, $past);
            }
            if ($step === $targetStatus) {
                break;
            }
        }

        return $session;
    }

    private function makeHandler(
        SessionRepositoryInterface $sessions,
        ?SessionReconcilerInterface $reconciler,
        RunnerGatewayInterface $gateway,
        ?HubInterface $hub = null,
        ?PersonalRunAdvancerInterface $personalRunAdvancer = null,
    ): CleanupStaleSessionsHandler {
        $reconciler ??= $this->createStub(SessionReconcilerInterface::class);
        return new CleanupStaleSessionsHandler(
            $sessions,
            $reconciler,
            $personalRunAdvancer ?? $this->createStub(PersonalRunAdvancerInterface::class),
            $hub ?? $this->createStub(HubInterface::class),
            $gateway,
            new NullLogger(),
        );
    }
}
