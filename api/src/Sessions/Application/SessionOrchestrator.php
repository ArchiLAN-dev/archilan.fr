<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\PersonalRun;
use App\Registrations\Domain\Registration;
use App\Sessions\Application\Message\GenerateRunJob;
use App\Sessions\Application\Message\RestartRunJob;
use App\Sessions\Application\Message\StartRunJob;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Infrastructure\RunnerGatewayInterface;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SessionOrchestrator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RunnerGatewayInterface $runnerGateway,
        private SessionLifecycleManager $sessionLifecycleManager,
        private MessageBusInterface $messageBus,
        private SlotNameGenerator $slotNameGenerator,
        private LoggerInterface $logger,
        private MinioStorageInterface $minioStorage,
        private string $minioApworldsBucket,
        private int $minioPresignTtl,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSessions(string $eventId): array
    {
        /** @var list<Session> $sessions */
        $sessions = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Session::class, 's')
            ->where('s.eventId = :eventId')
            ->setParameter('eventId', $eventId)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (Session $s) => $s->payload(), $sessions);
    }

    /**
     * @return array{registrations: list<array<string, mixed>>}
     */
    public function getBuilder(string $eventId): array
    {
        /** @var list<Registration> $registrations */
        $registrations = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Registration::class, 'r')
            ->where('r.eventId = :eventId')
            ->andWhere('r.status = :status')
            ->setParameter('eventId', $eventId)
            ->setParameter('status', Registration::STATUS_RESERVED)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        if ([] === $registrations) {
            return ['registrations' => []];
        }

        /** @var list<string> $userIds */
        $userIds = array_unique(array_map(static fn (Registration $r) => $r->getUserId(), $registrations));

        /** @var list<User> $users */
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', $userIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, User> $usersById */
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        $allGameIds = [];
        foreach ($registrations as $reg) {
            foreach ($reg->getGameSlots() as $slot) {
                $allGameIds[$slot['gameId']] = true;
            }
        }

        /** @var array<string, ArchipelagoGame> $gamesById */
        $gamesById = [];
        if ([] !== $allGameIds) {
            /** @var list<ArchipelagoGame> $games */
            $games = $this->entityManager->createQueryBuilder()
                ->select('g')
                ->from(ArchipelagoGame::class, 'g')
                ->where('g.id IN (:ids)')
                ->setParameter('ids', array_keys($allGameIds))
                ->getQuery()
                ->getResult();
            foreach ($games as $game) {
                $gamesById[$game->getId()] = $game;
            }
        }

        $result = [];
        foreach ($registrations as $reg) {
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
     * Auto-generate slot names, dispatch validate job, transition draft → validating.
     * Already-ready sessions are treated as validated so callers can keep a
     * validate-then-generate pipeline without exposing a separate UI step.
     *
     * @return array<string, mixed>
     */
    public function orchestrateValidate(string $sessionId): array
    {
        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        if (Session::STATUS_READY === $session->getStatus()) {
            return ['found' => true, 'session' => $session->payload()];
        }

        if (Session::STATUS_DRAFT !== $session->getStatus()) {
            return ['found' => true, 'errors' => ['La session doit être en état "draft" pour être validée.']];
        }

        /** @var list<SessionSlot> $sessionSlots */
        $sessionSlots = $this->entityManager
            ->getRepository(SessionSlot::class)
            ->findBy(['sessionId' => $sessionId], ['slotOrder' => 'ASC']);

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

        $messageSlots = [];
        foreach ($enriched as $i => $data) {
            $messageSlots[] = [
                'slotName' => $generatedNames[$i],
                'playerName' => $data['playerName'],
                'archipelagoGameName' => $data['archipelagoGameName'],
                'playerYaml' => $data['playerYaml'],
            ];
        }

        // transition() flushes → slot name updates are included
        $transitionResult = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_VALIDATING);
        if (!($transitionResult['found'] ?? false) || isset($transitionResult['errors'])) {
            return $transitionResult;
        }

        $this->messageBus->dispatch(new GenerateRunJob($sessionId, 'validate', $messageSlots));

        $this->logger->info('session.orchestrate.validate', [
            'sessionId' => $sessionId,
            'slotCount' => count($messageSlots),
        ]);

        return ['found' => true, 'session' => $transitionResult['session']];
    }

    /**
     * Transition ready → generating, dispatch GenerateRunJob{phase: generate}.
     *
     * @return array<string, mixed>
     */
    public function orchestrateGenerate(string $sessionId): array
    {
        $session = $this->entityManager->find(Session::class, $sessionId);
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

        $downloadUrls = $this->collectApworldDownloadUrls($sessionId);

        $this->messageBus->dispatch(new GenerateRunJob(
            $sessionId,
            'generate',
            apworldDownloadUrls: $downloadUrls,
        ));

        $this->logger->info('session.orchestrate.generate', [
            'sessionId' => $sessionId,
            'apworldUrlCount' => count($downloadUrls),
        ]);

        return ['found' => true, 'session' => $result['session']];
    }

    /**
     * Auto-advance a personal run session through the pipeline.
     * - ready     → triggers generate
     * - generated → triggers launch
     * No-op if the session doesn't belong to a personal run.
     */
    public function autoAdvancePersonalRun(string $sessionId): void
    {
        $personalRun = $this->entityManager->getRepository(PersonalRun::class)->findOneBy(['sessionId' => $sessionId]);
        if (!$personalRun instanceof PersonalRun) {
            return;
        }

        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            return;
        }

        if (Session::STATUS_READY === $session->getStatus()) {
            $this->orchestrateGenerate($sessionId);
            $this->logger->info('session.auto_advance.generate', ['sessionId' => $sessionId]);
        } elseif (Session::STATUS_GENERATED === $session->getStatus()) {
            $this->orchestrateLaunch($sessionId);
            $this->logger->info('session.auto_advance.launch', ['sessionId' => $sessionId]);
        }
    }

    /**
     * Transition generated → launching, dispatch StartRunJob.
     *
     * @return array<string, mixed>
     */
    public function orchestrateLaunch(string $sessionId): array
    {
        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        if (Session::STATUS_GENERATED !== $session->getStatus()) {
            return ['found' => true, 'errors' => ['La session doit être en état "generated" pour être lancée.']];
        }

        $result = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_LAUNCHING);
        if (!($result['found'] ?? false) || isset($result['errors'])) {
            return $result;
        }

        $this->messageBus->dispatch(new StartRunJob($sessionId));

        $this->logger->info('session.orchestrate.launch', ['sessionId' => $sessionId]);

        return ['found' => true, 'session' => $result['session']];
    }

    /**
     * Admin force-launch: bypass the "must be generated" guard.
     * Allowed from any state that has generated output on disk.
     *
     * @return array<string, mixed>
     */
    public function orchestrateForceLaunch(string $sessionId): array
    {
        $session = $this->entityManager->find(Session::class, $sessionId);
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

        $existingPort = $session->getPort();
        $existingBridgePort = $session->getBridgePort();
        $existingPassword = $session->getPassword();
        $existingServerPwd = $session->getServerPassword();

        $result = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_LAUNCHING);
        if (!($result['found'] ?? false) || isset($result['errors'])) {
            return $result;
        }

        $this->messageBus->dispatch(new StartRunJob(
            sessionId: $sessionId,
            existingPort: $existingPort,
            existingBridgePort: $existingBridgePort,
            existingPassword: $existingPassword,
            existingServerPassword: $existingServerPwd,
        ));

        $this->logger->info('session.orchestrate.force_launch', [
            'sessionId' => $sessionId,
            'reusingCredentials' => null !== $existingPassword,
        ]);

        return ['found' => true, 'session' => $result['session']];
    }

    /**
     * Optimistically transition running → stopped, dispatch StopRunJob (best-effort cleanup).
     *
     * @return array<string, mixed>
     */
    public function orchestrateStop(string $sessionId): array
    {
        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        $port = $session->getPort() ?? 0;
        $bridgePort = $session->getBridgePort() ?? 0;

        $result = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_STOPPED);
        if (!($result['found'] ?? false) || isset($result['errors'])) {
            return $result;
        }

        $this->messageBus->dispatch(new StopRunJob($sessionId, $port, $bridgePort));

        $this->logger->info('session.orchestrate.stop', ['sessionId' => $sessionId]);

        return ['found' => true, 'session' => $result['session']];
    }

    /**
     * Transition crashed → launching, dispatch RestartRunJob.
     *
     * @return array<string, mixed>
     */
    public function orchestrateRestart(string $sessionId): array
    {
        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            return ['found' => false];
        }

        if (Session::STATUS_CRASHED !== $session->getStatus()) {
            return ['found' => true, 'errors' => ['La session doit être en état "crashed" pour être relancée.']];
        }

        $port = $session->getPort() ?? 0;
        $bridgePort = $session->getBridgePort() ?? 0;
        $password = $session->getPassword() ?? '';
        $serverPassword = $session->getServerPassword() ?? '';

        $result = $this->sessionLifecycleManager->transition($sessionId, Session::STATUS_LAUNCHING);
        if (!($result['found'] ?? false) || isset($result['errors'])) {
            return $result;
        }

        $this->messageBus->dispatch(new RestartRunJob($sessionId, $port, $bridgePort, $password, $serverPassword));

        $this->logger->info('session.orchestrate.restart', ['sessionId' => $sessionId]);

        return ['found' => true, 'session' => $result['session']];
    }

    public function getYamlsZip(string $sessionId): null
    {
        // YAMLs live on the runner filesystem; central API cannot access them directly.
        return null;
    }

    /**
     * @param list<SessionSlot> $sessionSlots
     *
     * @return list<array{playerName: string, archipelagoGameName: string, playerYaml: string}>
     */
    private function enrichSlotsForValidation(array $sessionSlots): array
    {
        $registrationIds = array_unique(array_map(
            static fn (SessionSlot $s) => $s->getRegistrationId(),
            $sessionSlots,
        ));

        /** @var list<Registration> $registrations */
        $registrations = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Registration::class, 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $registrationIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, Registration> $regById */
        $regById = [];
        foreach ($registrations as $reg) {
            $regById[$reg->getId()] = $reg;
        }

        $userIds = array_unique(array_map(static fn (Registration $r) => $r->getUserId(), $registrations));

        /** @var list<User> $users */
        $users = [] !== $userIds
            ? $this->entityManager->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->where('u.id IN (:ids)')
                ->setParameter('ids', $userIds)
                ->getQuery()
                ->getResult()
            : [];

        /** @var array<string, User> $userById */
        $userById = [];
        foreach ($users as $user) {
            $userById[$user->getId()] = $user;
        }

        $gameIds = array_unique(array_map(
            static fn (SessionSlot $s) => $s->getGameId(),
            $sessionSlots,
        ));

        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(ArchipelagoGame::class, 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $gameIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, ArchipelagoGame> $gameById */
        $gameById = [];
        foreach ($games as $game) {
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
            $playerYaml = is_string($regSlot['playerYaml'] ?? null) ? $regSlot['playerYaml'] : '';

            $result[] = [
                'playerName' => $playerName,
                'archipelagoGameName' => $archipelagoGameName,
                'playerYaml' => $playerYaml,
            ];
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function collectApworldDownloadUrls(string $sessionId): array
    {
        $sessionSlots = $this->entityManager->getRepository(SessionSlot::class)
            ->findBy(['sessionId' => $sessionId]);

        $gameIds = array_unique(array_map(static fn (SessionSlot $s) => $s->getGameId(), $sessionSlots));

        if ([] === $gameIds) {
            return [];
        }

        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(ArchipelagoGame::class, 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $gameIds)
            ->getQuery()
            ->getResult();

        $downloadUrls = [];

        foreach ($games as $game) {
            $minioKey = $game->getApworldMinioKey();
            if (null !== $minioKey && !array_key_exists($minioKey, $downloadUrls)) {
                $downloadUrls[$minioKey] = $this->minioStorage->presignedUrl(
                    $this->minioApworldsBucket,
                    $minioKey,
                    $this->minioPresignTtl,
                );
            }
        }

        return $downloadUrls;
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

        $registrationIds = array_unique(array_map(static fn (array $s): string => $s['registrationId'], $slots));
        $gameIds = array_unique(array_map(static fn (array $s): string => $s['gameId'], $slots));

        /** @var list<Registration> $registrations */
        $registrations = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Registration::class, 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $registrationIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, Registration> $regById */
        $regById = [];
        foreach ($registrations as $registration) {
            $regById[$registration->getId()] = $registration;
        }

        /** @var list<User> $users */
        $users = [] !== $registrations
            ? $this->entityManager->createQueryBuilder()
                ->select('u')
                ->from(User::class, 'u')
                ->where('u.id IN (:ids)')
                ->setParameter('ids', array_unique(array_map(static fn (Registration $r): string => $r->getUserId(), $registrations)))
                ->getQuery()
                ->getResult()
            : [];

        /** @var array<string, User> $usersById */
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(ArchipelagoGame::class, 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $gameIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, ArchipelagoGame> $gameById */
        $gameById = [];
        foreach ($games as $game) {
            $gameById[$game->getId()] = $game;
        }

        $result = [];
        foreach ($slots as $slot) {
            $registration = $regById[$slot['registrationId']] ?? null;
            $game = $gameById[$slot['gameId']] ?? null;
            $registrationSlot = null !== ($slot['slotId'] ?? null) ? $registration?->getSlot((string) $slot['slotId']) : null;
            $user = $registration instanceof Registration ? ($usersById[$registration->getUserId()] ?? null) : null;

            $result[] = [
                'eventId' => $registration?->getEventId(),
                'slotId' => $slot['slotId'] ?? $slot['registrationId'].'-'.$slot['gameId'],
                'playerName' => $user?->getDisplayName() ?? $user?->getEmail() ?? $slot['registrationId'],
                'archipelagoGameName' => $game instanceof ArchipelagoGame ? ($game->getArchipelagoGameName() ?? '') : 'Unknown',
                'playerYaml' => $registrationSlot['playerYaml'] ?? null,
            ];
        }

        return $result;
    }
}
