<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Communications\Application\SessionPausedWithoutSaveMessage;
use App\Communications\Application\SessionRestartFailedMessage;
use App\Communications\Application\SessionRunningMessage;
use App\Events\Domain\Event;
use App\Events\Domain\EventRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use App\Sessions\Application\Message\ResumeRunJob;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SessionLifecycleManager implements SessionReconcilerInterface
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private RunRepositoryInterface $runs,
        private RegistrationRepositoryInterface $registrations,
        private UserRepositoryInterface $users,
        private EventRepositoryInterface $events,
        private HubInterface $mercureHub,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private RunnerGatewayInterface $runnerGateway,
        private string $runnerPublicHost = 'localhost',
    ) {
    }

    /**
     * @param list<array{registrationId: string, gameId: string, slotName: string, slotId?: string|null}> $slots
     *
     * @return array<string, mixed>
     */
    public function createSession(string $eventId, array $slots): array
    {
        $session = Session::create($this->generateId(), $eventId, new \DateTimeImmutable());
        $this->sessions->persist($session);

        foreach ($slots as $order => $slotData) {
            $slot = SessionSlot::create(
                $this->generateId(),
                $session->getId(),
                $slotData['registrationId'],
                $slotData['gameId'],
                $slotData['slotName'],
                $order,
                $slotData['slotId'] ?? null,
            );
            $this->slots->persist($slot);
        }

        $this->sessions->flush();
        $this->publish($session);

        $this->logger->info('session.created', ['sessionId' => $session->getId(), 'eventId' => $eventId, 'slotCount' => count($slots)]);

        return ['session' => $session->payload()];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSession(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        $slotsList = $this->slots->findBySessionId($sessionId);

        return [
            'found' => true,
            'session' => $session->payload(),
            'slots' => array_map(fn (SessionSlot $s) => $s->payload(), $slotsList),
        ];
    }

    /**
     * @param list<array{slotName: string, errors: list<string>}>|null $validationErrors
     *
     * @return array<string, mixed>
     */
    public function transition(
        string $sessionId,
        string $newStatus,
        ?string $host = null,
        ?int $port = null,
        ?string $password = null,
        ?array $validationErrors = null,
        ?int $bridgePort = null,
        ?string $runnerId = null,
        ?string $lastLogs = null,
        ?string $serverPassword = null,
    ): array {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        $now = new \DateTimeImmutable();

        if (null !== $runnerId && !$session->isLockedTo($runnerId)) {
            $this->logger->warning('session.transition.runner_mismatch', [
                'sessionId' => $sessionId,
                'expected' => $session->getRunnerId(),
                'got' => $runnerId,
                'to' => $newStatus,
            ]);

            return ['found' => true, 'errors' => ["Runner '$runnerId' non autorisé à modifier cette session."]];
        }

        $fromStatus = $session->getStatus();

        try {
            $session->transition($newStatus, $now, $host, $port, $password, $bridgePort, $serverPassword);
        } catch (\LogicException $e) {
            $this->logger->warning('session.transition.rejected', ['sessionId' => $sessionId, 'from' => $fromStatus, 'to' => $newStatus]);

            return ['found' => true, 'errors' => [$e->getMessage()]];
        }

        if (null !== $runnerId && null === $session->getRunnerId()) {
            $session->lockTo($runnerId, $now);
        }

        if (Session::STATUS_DRAFT === $newStatus && null !== $validationErrors) {
            $session->setValidationErrors($validationErrors);

            $personalRun = $this->runs->findBySessionId($sessionId);
            if ($personalRun instanceof Run && Run::STATUS_STARTING === $personalRun->getStatus()) {
                $personalRun->resetAfterValidationFailure($now);
            }
        }

        if (null !== $lastLogs && in_array($newStatus, [Session::STATUS_FAILED, Session::STATUS_CRASHED], true)) {
            $session->setLastLogs($lastLogs);
        }

        $shouldNotify = Session::STATUS_RUNNING === $newStatus && !$session->isNotified();
        if ($shouldNotify) {
            $session->markNotified($now);
        }

        if (Session::STATUS_RUNNING === $newStatus) {
            $personalRun = $this->runs->findBySessionId($sessionId);
            if ($personalRun instanceof Run) {
                $personalRun->markRunning($session->getHost() ?? '', $session->getPort() ?? 0, $now, $session->getPassword());
            }
        }

        if (Session::STATUS_CRASHED === $newStatus) {
            $personalRun = $this->runs->findBySessionId($sessionId);
            if ($personalRun instanceof Run) {
                $session->markIdle($session->getLastSaveKey(), true, $now);
                $personalRun->markStopped($now);
                $this->logger->info('session.crash_recovered_to_idle', ['sessionId' => $sessionId]);
            }
        }

        $this->sessions->flush();
        $this->publish($session);

        $this->logger->info('session.transition', ['sessionId' => $sessionId, 'from' => $fromStatus, 'to' => $session->getStatus()]);

        if ($shouldNotify) {
            $this->dispatchRunningNotifications($session);
        }

        return ['found' => true, 'session' => $session->payload()];
    }

    /** @return array{found: bool} */
    public function heartbeat(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return ['found' => true];
        }

        $session->updateHeartbeat(new \DateTimeImmutable());
        $this->sessions->flush();

        return ['found' => true];
    }

    /** @return array{found: bool, session?: array<string, mixed>} */
    public function forceReset(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        $previous = $session->getStatus();

        $session->forceReset(new \DateTimeImmutable());
        $this->sessions->flush();
        $this->publish($session);

        $this->logger->warning('session.force_reset', [
            'sessionId' => $sessionId,
            'previousStatus' => $previous,
        ]);

        $containerStatuses = [Session::STATUS_RUNNING, Session::STATUS_LAUNCHING, Session::STATUS_CRASHED];
        if (in_array($previous, $containerStatuses, true)) {
            $this->runnerGateway->stopSession($sessionId);
        }

        return ['found' => true, 'session' => $session->payload()];
    }

    public function storePendingCredentials(
        string $sessionId,
        ?string $adminPassword = null,
        ?string $host = null,
        ?string $password = null,
    ): void {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return;
        }

        $session->storePendingCredentials($adminPassword, $host, $password);
        $this->sessions->flush();
    }

    /**
     * Transitions a session to RUNNING using credentials pre-stored before launch.
     * Called by the orchestrateur webhook on session.ready.
     *
     * If the session was marked CRASHED by the cleanup handler before the webhook
     * arrived (a timing race), step it through LAUNCHING first so the state
     * machine allows the RUNNING transition.
     *
     * @return array<string, mixed>
     */
    public function transitionToRunningFromOrchestrateur(string $sessionId, int $apPort, ?int $bridgePort): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        if (Session::STATUS_CRASHED === $session->getStatus()) {
            $now = new \DateTimeImmutable();
            try {
                $session->transition(Session::STATUS_LAUNCHING, $now);
                $this->sessions->flush();
            } catch (\LogicException $e) {
                $this->logger->warning('session.transition.rejected', [
                    'sessionId' => $sessionId,
                    'from' => 'crashed',
                    'to' => Session::STATUS_LAUNCHING,
                ]);

                return ['found' => true, 'errors' => [$e->getMessage()]];
            }
        }

        $host = $this->runnerPublicHost;
        $password = $session->getPassword() ?? '';
        $serverPassword = $session->getAdminPassword();

        return $this->transition($sessionId, Session::STATUS_RUNNING, $host, $apPort, $password, null, $bridgePort, null, null, $serverPassword);
    }

    private function dispatchRunningNotifications(Session $session): void
    {
        $slotsList = $this->slots->findBySessionId($session->getId());

        if ([] === $slotsList) {
            return;
        }

        /** @var array<string, list<string>> $slotNamesByRegistrationId */
        $slotNamesByRegistrationId = [];
        foreach ($slotsList as $slot) {
            $slotNamesByRegistrationId[$slot->getRegistrationId()][] = $slot->getSlotName();
        }

        $registrationIds = array_keys($slotNamesByRegistrationId);

        /** @var list<Registration> $regList */
        $regList = $this->registrations->findBy(['id' => $registrationIds]);

        if ([] === $regList) {
            return;
        }

        $userIds = array_values(array_unique(array_map(static fn (Registration $r) => $r->getUserId(), $regList)));

        $usersList = $this->users->findByIds($userIds);

        /** @var array<string, \App\Identity\Domain\User> $usersById */
        $usersById = [];
        foreach ($usersList as $user) {
            $usersById[$user->getId()] = $user;
        }

        $event = $this->events->findById($session->getEventId());
        $eventTitle = $event instanceof Event ? $event->getTitle() : $session->getEventId();

        foreach ($regList as $registration) {
            $user = $usersById[$registration->getUserId()] ?? null;
            if (null === $user) {
                continue;
            }

            $this->messageBus->dispatch(new SessionRunningMessage(
                sessionId: $session->getId(),
                registrationId: $registration->getId(),
                userId: $user->getId(),
                userEmail: $user->getEmail(),
                userDisplayName: $user->getDisplayName(),
                eventTitle: $eventTitle,
                host: $session->getHost() ?? '',
                port: $session->getPort() ?? 0,
                password: $session->getPassword() ?? '',
                slotNames: $slotNamesByRegistrationId[$registration->getId()] ?? [],
            ));
        }

        $this->logger->info('session.notifications.dispatched', ['sessionId' => $session->getId(), 'count' => count($regList)]);
    }

    /**
     * @param list<array<string, mixed>> $slots
     *
     * @return array{found: bool}
     */
    public function storeArchive(
        string $sessionId,
        ?string $archivedSavePath,
        ?string $archivedSpoilerPath,
        array $slots,
    ): array {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        $session->setArchivedSavePath($archivedSavePath);
        $session->setArchivedSpoilerPath($archivedSpoilerPath);

        foreach ($slots as $slotData) {
            $slotName = is_string($slotData['slot_name'] ?? null) ? $slotData['slot_name'] : null;
            if (null === $slotName || '' === $slotName) {
                continue;
            }

            $slot = $this->slots->findBySessionAndSlotName($sessionId, $slotName);

            if (!$slot instanceof SessionSlot) {
                continue;
            }

            $slot->setChecksDone(is_int($slotData['checks_done'] ?? null) ? $slotData['checks_done'] : 0);
            $slot->setItemsReceived(is_int($slotData['items_received'] ?? null) ? $slotData['items_received'] : 0);

            $goalAt = $slotData['goal_reached_at'] ?? null;
            if (is_string($goalAt)) {
                try {
                    $slot->setGoalReachedAt(new \DateTimeImmutable($goalAt));
                } catch (\Throwable) {
                    $slot->setGoalReachedAt(null);
                }
            } else {
                $slot->setGoalReachedAt(null);
            }
        }

        $this->sessions->flush();

        $this->logger->info('session.archive.stored', ['sessionId' => $sessionId, 'slot_count' => count($slots)]);

        return ['found' => true];
    }

    /**
     * @return array{found: bool, error: string|null, sessionId: string|null, status: string|null}
     */
    public function initiateRestart(string $sessionId, string $callerId, bool $isAdmin): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false, 'error' => null, 'sessionId' => null, 'status' => null];
        }

        if (Session::STATUS_IDLE !== $session->getStatus()) {
            return ['found' => true, 'error' => 'invalid_session_status', 'sessionId' => null, 'status' => null];
        }

        if ($session->isPausedWithoutSave() || null === $session->getLastSaveKey()) {
            return ['found' => true, 'error' => 'no_save_available', 'sessionId' => null, 'status' => null];
        }

        $personalRun = $this->runs->findBySessionId($sessionId);

        if (!$isAdmin) {
            if (!$personalRun instanceof Run || !$personalRun->isOwnedBy($callerId)) {
                return ['found' => true, 'error' => 'forbidden', 'sessionId' => null, 'status' => null];
            }
        }

        $now = new \DateTimeImmutable();
        $session->markRestarting($now);

        if ($personalRun instanceof Run) {
            $personalRun->markRestarting($now);
        }

        $this->sessions->flush();
        $this->publish($session);

        $saveKey = $session->getLastSaveKey();
        \assert(null !== $saveKey);

        $this->messageBus->dispatch(new ResumeRunJob(
            $sessionId,
            $saveKey,
            $session->getPassword() ?? '',
            $session->getServerPassword() ?? '',
            $session->getBridgePort() ?? 0,
        ));

        $this->logger->info('session.restart.initiated', ['sessionId' => $sessionId]);

        return ['found' => true, 'error' => null, 'sessionId' => $sessionId, 'status' => $session->getStatus()];
    }

    /**
     * @return array{found: bool, status: string|null}
     */
    public function recordRestarted(string $sessionId, string $host, int $port, int $bridgePort): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false, 'status' => null];
        }

        if (Session::STATUS_RUNNING === $session->getStatus()) {
            return ['found' => true, 'status' => 'already_running'];
        }

        if (Session::STATUS_RESTARTING !== $session->getStatus()) {
            return ['found' => true, 'status' => 'unexpected_status'];
        }

        $now = new \DateTimeImmutable();

        $effectiveHost = '' !== $host ? $host : ($session->getHost() ?? '');
        $effectivePort = $port > 0 ? $port : ($session->getPort() ?? 0);
        $effectiveBridgePort = $bridgePort > 0 ? $bridgePort : ($session->getBridgePort() ?? 0);

        $session->resumeRunning($effectiveHost, $effectivePort, $effectiveBridgePort, $now);

        $personalRun = $this->runs->findBySessionId($sessionId);
        if ($personalRun instanceof Run) {
            $personalRun->markRunning($effectiveHost, $effectivePort, $now, $session->getPassword());
        }

        $this->sessions->flush();
        $this->publish($session);

        $this->logger->info('session.restarted', ['sessionId' => $sessionId, 'host' => $effectiveHost, 'port' => $effectivePort]);

        return ['found' => true, 'status' => 'running'];
    }

    /**
     * @return array{found: bool, error: string|null, status: string|null}
     */
    public function markRestartFailed(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false, 'error' => null, 'status' => null];
        }

        if (Session::STATUS_IDLE === $session->getStatus()) {
            return ['found' => true, 'error' => null, 'status' => 'already_idle'];
        }

        if (Session::STATUS_RESTARTING !== $session->getStatus()) {
            return ['found' => true, 'error' => 'invalid_status', 'status' => null];
        }

        $now = new \DateTimeImmutable();

        try {
            $session->markRestartFailed($now);
        } catch (\LogicException $e) {
            return ['found' => true, 'error' => $e->getMessage(), 'status' => null];
        }

        $personalRun = $this->runs->findBySessionId($sessionId);
        if ($personalRun instanceof Run) {
            $personalRun->markStopped($now);
        }

        $this->sessions->flush();
        $this->publish($session);
        $this->messageBus->dispatch(new SessionRestartFailedMessage($sessionId));

        $this->logger->warning('session.restart_failed', ['sessionId' => $sessionId]);

        return ['found' => true, 'error' => null, 'status' => 'idle'];
    }

    /**
     * @return array{found: bool, status: string|null}
     */
    public function recordPaused(string $sessionId, ?string $saveKey, bool $failedSave): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false, 'status' => null];
        }

        if (Session::STATUS_IDLE === $session->getStatus()) {
            return ['found' => true, 'status' => 'already_idle'];
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return ['found' => true, 'status' => 'unexpected_status'];
        }

        $now = new \DateTimeImmutable();
        $session->markIdle($saveKey, $failedSave, $now);

        $personalRun = $this->runs->findBySessionId($sessionId);
        if ($personalRun instanceof Run) {
            $personalRun->markStopped($now);
        }

        $this->sessions->flush();
        $this->publish($session);

        $this->logger->info('session.paused', [
            'sessionId' => $sessionId,
            'saveKey' => $saveKey,
            'failedSave' => $failedSave,
        ]);

        if ($failedSave) {
            $this->messageBus->dispatch(new SessionPausedWithoutSaveMessage($sessionId));
        }

        return ['found' => true, 'status' => 'paused'];
    }

    /** @return array{found: bool} */
    public function recordActivity(string $sessionId, \DateTimeImmutable $occurredAt): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        $session->recordActivity($occurredAt);
        $this->sessions->flush();

        return ['found' => true];
    }

    /** @return array{found: bool} */
    public function storeLogs(string $sessionId, string $output): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        $session->setLastLogs($output);
        $this->sessions->flush();

        $this->logger->info('session.logs.stored', ['sessionId' => $sessionId, 'length' => strlen($output)]);

        return ['found' => true];
    }

    private function publish(Session $session): void
    {
        $update = new Update(
            sprintf('/sessions/%s', $session->getId()),
            json_encode($session->payload(), JSON_THROW_ON_ERROR),
        );
        try {
            $this->mercureHub->publish($update);
        } catch (\Throwable $e) {
            $this->logger->warning('session.mercure.publish_failed', [
                'sessionId' => $session->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
