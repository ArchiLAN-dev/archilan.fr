<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class SessionOrchestrator implements PersonalRunAdvancerInterface
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private RegistrationRepositoryInterface $registrations,
        private UserRepositoryInterface $users,
        private GameRepositoryInterface $games,
        private RunRepositoryInterface $runs,
        private RunnerGatewayInterface $runnerGateway,
        private SessionLifecycleManager $sessionLifecycleManager,
        private SlotNameGenerator $slotNameGenerator,
        private LoggerInterface $logger,
        private string $runnerPublicHost,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSessions(string $eventId): array
    {
        $sessionList = $this->sessions->findByEventId($eventId);

        return array_map(static fn (Session $s) => $s->payload(), $sessionList);
    }

    /**
     * @return array{registrations: list<array<string, mixed>>}
     */
    public function getBuilder(string $eventId): array
    {
        /** @var list<Registration> $regList */
        $regList = $this->registrations->findBy(
            ['eventId' => $eventId, 'status' => Registration::STATUS_RESERVED],
            ['createdAt' => 'ASC'],
        );

        if ([] === $regList) {
            return ['registrations' => []];
        }

        $userIds = array_values(array_unique(array_map(static fn (Registration $r) => $r->getUserId(), $regList)));

        $usersList = $this->users->findByIds($userIds);

        /** @var array<string, \App\Identity\Domain\User> $usersById */
        $usersById = [];
        foreach ($usersList as $user) {
            $usersById[$user->getId()] = $user;
        }

        $allGameIds = [];
        foreach ($regList as $reg) {
            foreach ($reg->getGameSlots() as $slot) {
                $allGameIds[$slot['gameId']] = true;
            }
        }

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        if ([] !== $allGameIds) {
            $gamesList = $this->games->findByIds(array_keys($allGameIds));
            foreach ($gamesList as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $result = [];
        foreach ($regList as $reg) {
            $user = $usersById[$reg->getUserId()] ?? null;
            $playerName = $user?->getDisplayName() ?? $user?->getEmail() ?? $reg->getUserId();

            $slots = [];
            foreach ($reg->getGameSlots() as $slot) {
                $game = $gamesById[$slot['gameId']] ?? null;
                $slots[] = [
                    'slotId' => $slot['slotId'],
                    'gameId' => $slot['gameId'],
                    'slotOrder' => $slot['slotOrder'],
                    'gameName' => $game?->getName() ?? $slot['gameId'],
                    'archipelagoGameName' => $game?->getArchipelagoGameName(),
                    'playerYaml' => $slot['playerYaml'] ?? null,
                ];
            }

            $result[] = [
                'registrationId' => $reg->getId(),
                'playerName' => $playerName,
                'slots' => $slots,
            ];
        }

        return ['registrations' => $result];
    }

    /**
     * @param list<array<string, mixed>> $slots
     *
     * @return array<string, mixed>
     */
    public function preflight(string $eventId, array $slots): array
    {
        /** @var list<array{registrationId: string, gameId: string, slotName: string, slotId?: string|null}> $slots */
        $enriched = $this->buildPreflightSlotsForCreation($slots);

        return $this->runnerGateway->preflight($eventId, $enriched);
    }

    /**
     * @param list<array{registrationId: string, gameId: string, slotName: string, slotId?: string|null}> $slots
     *
     * @return array{valid: bool, errors: array<string, list<string>>}
     */
    public function validateCreation(string $eventId, array $slots): array
    {
        $errors = $this->validateRequestedSlotNames($slots);

        $preflightSlots = $this->buildPreflightSlotsForCreation($slots);
        foreach ($preflightSlots as $preflightSlot) {
            if (($preflightSlot['eventId'] ?? $eventId) !== $eventId) {
                $errors['slots'][] = 'Une inscription ne correspond pas a cet evenement.';
            }
        }

        $preflight = $this->runnerGateway->preflight($eventId, $preflightSlots);
        if (($preflight['error'] ?? null) === 'runner_unavailable') {
            $errors['runner'][] = 'Le runner est indisponible.';
        }

        if (isset($preflight['slots']) && is_array($preflight['slots'])) {
            foreach ($preflight['slots'] as $slotResult) {
                if (!is_array($slotResult)) {
                    continue;
                }

                $slotErrors = $slotResult['errors'] ?? [];
                if (!is_array($slotErrors)) {
                    continue;
                }

                $slotId = is_string($slotResult['slotId'] ?? null) ? $slotResult['slotId'] : 'slot';
                foreach ($slotErrors as $slotError) {
                    $errors[$slotId][] = is_string($slotError) ? $slotError : '';
                }
            }
        }

        return ['valid' => [] === $errors, 'errors' => $errors];
    }

    /**
     * @return array<string, mixed>
     */
    public function orchestrateValidate(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        if (Session::STATUS_READY === $session->getStatus()) {
            return ['found' => true, 'session' => $session->payload()];
        }

        if (Session::STATUS_DRAFT !== $session->getStatus()) {
            return ['found' => true, 'errors' => ['La session doit être en état "draft" pour être validée.']];
        }

        $sessionSlots = $this->slots->findBySessionId($sessionId);

        if ([] === $sessionSlots) {
            return ['found' => true, 'errors' => ['La session ne contient aucun slot.']];
        }

        $enriched = $this->enrichSlotsForValidation($sessionSlots);

        $generatorInput = array_map(static fn (array $s) => [
            'playerName' => $s['playerName'],
            'archipelagoGameName' => $s['archipelagoGameName'],
        ], $enriched);

        $generatedNames = $this->slotNameGenerator->generate($generatorInput);

        foreach ($sessionSlots as $i => $slot) {
            $slot->setSlotName($generatedNames[$i]);
        }

        $transitionResult = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_VALIDATING);
        if (!($transitionResult['found'] ?? false) || isset($transitionResult['errors'])) {
            return $transitionResult;
        }

        $configureSlots = [];
        foreach ($enriched as $i => $data) {
            $configureSlots[] = [
                'slotName' => $generatedNames[$i],
                'apworldHash' => $data['apworldHash'],
                'playerYaml' => $data['playerYaml'],
            ];
        }

        $configureResult = $this->runnerGateway->configureSession($sessionId, $configureSlots);

        if (!$configureResult['valid'] || [] !== $configureResult['errors']) {
            $validationErrors = array_map(
                static fn (array $e) => ['slotName' => $e['playerName'], 'errors' => $e['errors']],
                $configureResult['errors'],
            );
            $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_DRAFT, validationErrors: $validationErrors);

            $this->logger->warning('session.orchestrate.validate.configure_failed', [
                'sessionId' => $sessionId,
                'errors' => $configureResult['errors'],
            ]);

            return ['found' => true, 'errors' => $configureResult['errors']];
        }

        $readyResult = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_READY);

        $this->logger->info('session.orchestrate.validate', [
            'sessionId' => $sessionId,
            'slotCount' => count($configureSlots),
        ]);

        return $readyResult;
    }

    /**
     * @return array<string, mixed>
     */
    public function orchestrateGenerate(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        if (Session::STATUS_READY !== $session->getStatus()) {
            return ['found' => true, 'errors' => ['La session doit être en état "ready" pour lancer la génération.']];
        }

        $result = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_GENERATING);
        if (!($result['found'] ?? false) || isset($result['errors'])) {
            return $result;
        }

        $adminPassword = bin2hex(random_bytes(16));
        $this->sessionLifecycleManager->storePendingCredentials($sessionId, adminPassword: $adminPassword);

        $this->runnerGateway->generateSession($sessionId, $adminPassword);

        $this->logger->info('session.orchestrate.generate', ['sessionId' => $sessionId]);

        return ['found' => true, 'session' => $result['session']];
    }

    public function markPersonalRunStopped(string $sessionId): void
    {
        $personalRun = $this->runs->findBySessionId($sessionId);
        if (!$personalRun instanceof Run) {
            return;
        }

        try {
            $personalRun->markStopped(new \DateTimeImmutable());
            $this->runs->flush();
            $this->logger->info('session.personal_run.marked_stopped', ['sessionId' => $sessionId]);
        } catch (\Throwable $e) {
            $this->logger->error('session.personal_run.mark_stopped_failed', [
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function autoAdvancePersonalRun(string $sessionId): void
    {
        $personalRun = $this->runs->findBySessionId($sessionId);
        if (!$personalRun instanceof Run) {
            return;
        }

        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return;
        }

        try {
            if (Session::STATUS_READY === $session->getStatus()) {
                $this->orchestrateGenerate($sessionId);
                $this->logger->info('session.auto_advance.generate', ['sessionId' => $sessionId]);
            } elseif (Session::STATUS_GENERATED === $session->getStatus()) {
                $this->orchestrateLaunch($sessionId);
                $this->logger->info('session.auto_advance.launch', ['sessionId' => $sessionId]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('session.auto_advance.failed', [
                'sessionId' => $sessionId,
                'status' => $session->getStatus(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function orchestrateLaunch(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        if (Session::STATUS_GENERATED !== $session->getStatus()) {
            return ['found' => true, 'errors' => ['La session doit être en état "generated" pour être lancée.']];
        }

        $adminPassword = $session->getAdminPassword() ?? bin2hex(random_bytes(16));
        $serverPassword = bin2hex(random_bytes(8));

        $result = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_LAUNCHING);
        if (!($result['found'] ?? false) || isset($result['errors'])) {
            return $result;
        }

        $this->sessionLifecycleManager->storePendingCredentials(
            $sessionId,
            host: $this->runnerPublicHost,
            password: $serverPassword,
        );

        $this->runnerGateway->launchSession($sessionId, $adminPassword, $serverPassword);

        $this->logger->info('session.orchestrate.launch', ['sessionId' => $sessionId]);

        return ['found' => true, 'session' => $result['session']];
    }

    /**
     * @return array<string, mixed>
     */
    public function orchestrateForceLaunch(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        $allowed = [
            Session::STATUS_GENERATED,
            Session::STATUS_RUNNING,
            Session::STATUS_STOPPED,
            Session::STATUS_FAILED,
            Session::STATUS_CRASHED,
            Session::STATUS_FINISHED,
        ];

        if (!in_array($session->getStatus(), $allowed, true)) {
            return ['found' => true, 'errors' => ['Impossible de lancer le container depuis l\'état "'.$session->getStatus().'".']];
        }

        $adminPassword = $session->getAdminPassword() ?? bin2hex(random_bytes(16));
        $existingPassword = $session->getPassword();
        $serverPassword = null !== $existingPassword && '' !== $existingPassword ? $existingPassword : bin2hex(random_bytes(8));

        $result = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_LAUNCHING);
        if (!($result['found'] ?? false) || isset($result['errors'])) {
            return $result;
        }

        $this->sessionLifecycleManager->storePendingCredentials(
            $sessionId,
            host: $this->runnerPublicHost,
            password: $serverPassword,
        );

        $this->runnerGateway->launchSession($sessionId, $adminPassword, $serverPassword);

        $this->logger->info('session.orchestrate.force_launch', [
            'sessionId' => $sessionId,
            'reusingCredentials' => null !== $existingPassword,
        ]);

        return ['found' => true, 'session' => $result['session']];
    }

    /**
     * @return array<string, mixed>
     */
    public function orchestrateStop(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        $result = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_STOPPED);
        if (!($result['found'] ?? false) || isset($result['errors'])) {
            return $result;
        }

        $this->runnerGateway->stopSession($sessionId);

        $this->logger->info('session.orchestrate.stop', ['sessionId' => $sessionId]);

        return ['found' => true, 'session' => $result['session']];
    }

    /**
     * @return array<string, mixed>
     */
    public function orchestrateRestart(string $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        if (Session::STATUS_CRASHED !== $session->getStatus()) {
            return ['found' => true, 'errors' => ['La session doit être en état "crashed" pour être relancée.']];
        }

        $result = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_LAUNCHING);
        if (!($result['found'] ?? false) || isset($result['errors'])) {
            return $result;
        }

        $this->runnerGateway->restartSession($sessionId);

        $this->logger->info('session.orchestrate.restart', ['sessionId' => $sessionId]);

        return ['found' => true, 'session' => $result['session']];
    }

    public function getYamlsZip(string $sessionId): null
    {
        return null;
    }

    /**
     * @param list<SessionSlot> $sessionSlots
     *
     * @return list<array{playerName: string, archipelagoGameName: string, playerYaml: string, apworldHash: string}>
     */
    private function enrichSlotsForValidation(array $sessionSlots): array
    {
        $registrationIds = array_values(array_unique(array_map(
            static fn (SessionSlot $s) => $s->getRegistrationId(),
            $sessionSlots,
        )));

        /** @var list<Registration> $regList */
        $regList = $this->registrations->findBy(['id' => $registrationIds]);

        /** @var array<string, Registration> $regById */
        $regById = [];
        foreach ($regList as $reg) {
            $regById[$reg->getId()] = $reg;
        }

        $userIds = array_values(array_unique(array_map(static fn (Registration $r) => $r->getUserId(), $regList)));

        $usersList = [] !== $userIds ? $this->users->findByIds($userIds) : [];

        /** @var array<string, \App\Identity\Domain\User> $userById */
        $userById = [];
        foreach ($usersList as $user) {
            $userById[$user->getId()] = $user;
        }

        $gameIds = array_values(array_unique(array_map(
            static fn (SessionSlot $s) => $s->getGameId(),
            $sessionSlots,
        )));

        $gamesList = $this->games->findByIds($gameIds);

        /** @var array<string, Game> $gameById */
        $gameById = [];
        foreach ($gamesList as $game) {
            $gameById[$game->getId()] = $game;
        }

        $result = [];
        foreach ($sessionSlots as $slot) {
            $reg = $regById[$slot->getRegistrationId()] ?? null;
            $user = null !== $reg ? ($userById[$reg->getUserId()] ?? null) : null;
            $game = $gameById[$slot->getGameId()] ?? null;
            $regSlot = null !== $slot->getSlotId() ? $reg?->getSlot($slot->getSlotId()) : null;

            $playerName = $user?->getDisplayName() ?? $user?->getEmail() ?? $slot->getRegistrationId();
            $archipelagoGameName = $game?->getArchipelagoGameName() ?? '';
            $savedYaml = is_string($regSlot['playerYaml'] ?? null) ? $regSlot['playerYaml'] : '';
            // No saved YAML means the player kept the defaults — fall back to the game's
            // default template so generation succeeds without an explicit save.
            $playerYaml = '' !== $savedYaml ? $savedYaml : ($game?->getDefaultYaml() ?? '');

            $result[] = [
                'playerName' => $playerName,
                'archipelagoGameName' => $archipelagoGameName,
                'playerYaml' => $playerYaml,
                'apworldHash' => $game?->getApworldHash() ?? '',
            ];
        }

        return $result;
    }

    /**
     * @param list<array{registrationId: string, gameId: string, slotName: string, slotId?: string|null}> $slots
     *
     * @return array<string, list<string>>
     */
    private function validateRequestedSlotNames(array $slots): array
    {
        $errors = [];
        $seen = [];

        foreach ($slots as $slot) {
            $slotName = trim($slot['slotName']);
            if ('' === $slotName) {
                $errors['slotName'][] = 'Le nom de slot est requis.';
            }

            if (mb_strlen($slotName) > 16) {
                $errors['slotName'][] = sprintf('Le nom de slot "%s" depasse 16 caracteres.', $slotName);
            }

            if (isset($seen[$slotName])) {
                $errors['slotName'][] = sprintf('Le nom de slot "%s" est utilise plusieurs fois.', $slotName);
            }
            $seen[$slotName] = true;
        }

        return $errors;
    }

    /**
     * @param list<array{registrationId: string, gameId: string, slotName: string, slotId?: string|null}> $slots
     *
     * @return list<array<string, mixed>>
     */
    private function buildPreflightSlotsForCreation(array $slots): array
    {
        if ([] === $slots) {
            return [];
        }

        $registrationIds = array_values(array_unique(array_map(static fn (array $s): string => $s['registrationId'], $slots)));
        $gameIds = array_values(array_unique(array_map(static fn (array $s): string => $s['gameId'], $slots)));

        /** @var list<Registration> $regList */
        $regList = $this->registrations->findBy(['id' => $registrationIds]);

        /** @var array<string, Registration> $regById */
        $regById = [];
        foreach ($regList as $registration) {
            $regById[$registration->getId()] = $registration;
        }

        $userIds = array_values(array_unique(array_map(static fn (Registration $r): string => $r->getUserId(), $regList)));

        $usersList = [] !== $regList ? $this->users->findByIds($userIds) : [];

        /** @var array<string, \App\Identity\Domain\User> $usersById */
        $usersById = [];
        foreach ($usersList as $user) {
            $usersById[$user->getId()] = $user;
        }

        $gamesList = $this->games->findByIds($gameIds);

        /** @var array<string, Game> $gameById */
        $gameById = [];
        foreach ($gamesList as $game) {
            $gameById[$game->getId()] = $game;
        }

        $result = [];
        foreach ($slots as $slot) {
            $registration = $regById[$slot['registrationId']] ?? null;
            $game = $gameById[$slot['gameId']] ?? null;
            $registrationSlot = null !== ($slot['slotId'] ?? null) ? $registration?->getSlot((string) $slot['slotId']) : null;
            $user = $registration instanceof Registration ? ($usersById[$registration->getUserId()] ?? null) : null;

            $savedYaml = is_string($registrationSlot['playerYaml'] ?? null) ? $registrationSlot['playerYaml'] : '';
            $playerYaml = '' !== $savedYaml ? $savedYaml : ($game instanceof Game ? ($game->getDefaultYaml() ?? '') : '');

            $result[] = [
                'eventId' => $registration?->getEventId(),
                'slotId' => $slot['slotId'] ?? $slot['registrationId'].'-'.$slot['gameId'],
                'playerName' => $user?->getDisplayName() ?? $user?->getEmail() ?? $slot['registrationId'],
                'archipelagoGameName' => $game instanceof Game ? ($game->getArchipelagoGameName() ?? '') : 'Unknown',
                'playerYaml' => $playerYaml,
            ];
        }

        return $result;
    }
}
