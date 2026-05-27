<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Domain\UserRepositoryInterface;
use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Application\SlotNameGenerator;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;
use App\Sessions\Infrastructure\RunnerGatewayInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class LaunchPersonalRunJobHandler
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private UserRepositoryInterface $users,
        private GameRepositoryInterface $games,
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private SlotNameGenerator $slotNameGenerator,
        private RunnerGatewayInterface $runnerGateway,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LaunchPersonalRunJob $job): void
    {
        $run = $this->runs->findById($job->personalRunId);
        if (!$run instanceof Run) {
            $this->logger->error('personal_run.launch.not_found', ['runId' => $job->personalRunId]);

            return;
        }

        $participants = $this->participants->findByRunId($run->getId());

        $slotsForSession = [];
        foreach ($participants as $participant) {
            foreach ($participant->getGameSlots() as $slot) {
                $slotsForSession[] = [
                    'userId' => $participant->getUserId(),
                    'slotId' => $slot['slotId'],
                    'gameId' => $slot['gameId'],
                    'slotOrder' => $slot['slotOrder'],
                    'playerYaml' => $slot['playerYaml'] ?? '',
                ];
            }
        }

        if ([] === $slotsForSession) {
            $this->logger->error('personal_run.launch.no_slots', ['runId' => $job->personalRunId]);

            return;
        }

        $userIds = array_values(array_unique(array_column($slotsForSession, 'userId')));
        $gameIds = array_values(array_unique(array_column($slotsForSession, 'gameId')));

        $users = $this->users->findByIds($userIds);
        /** @var array<string, \App\Identity\Domain\User> $usersById */
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        $foundGames = $this->games->findByIds($gameIds);
        /** @var array<string, \App\GameSelection\Domain\Game> $gamesById */
        $gamesById = [];
        foreach ($foundGames as $game) {
            $gamesById[$game->getId()] = $game;
        }

        $generatorInput = [];
        foreach ($slotsForSession as $slot) {
            $user = $usersById[$slot['userId']] ?? null;
            $game = $gamesById[$slot['gameId']] ?? null;
            $generatorInput[] = [
                'playerName' => $user?->getDisplayName() ?? $user?->getEmail() ?? $slot['userId'],
                'archipelagoGameName' => $game?->getArchipelagoGameName() ?? '',
            ];
        }

        $slotNames = $this->slotNameGenerator->generate($generatorInput);

        $now = new \DateTimeImmutable();
        $sessionId = bin2hex(random_bytes(16));
        $session = Session::create($sessionId, $run->getId(), $now);
        $this->sessions->persist($session);

        $messageSlots = [];
        foreach ($slotsForSession as $i => $slot) {
            $user = $usersById[$slot['userId']] ?? null;
            $game = $gamesById[$slot['gameId']] ?? null;
            $slotName = $slotNames[$i];
            $playerName = $user?->getDisplayName() ?? $user?->getEmail() ?? $slot['userId'];
            $archipelagoGameName = $game?->getArchipelagoGameName() ?? '';

            $sessionSlot = SessionSlot::create(
                bin2hex(random_bytes(16)),
                $sessionId,
                $slot['userId'],
                $slot['gameId'],
                $slotName,
                $slot['slotOrder'],
                $slot['slotId'],
            );
            $this->slots->persist($sessionSlot);

            $playerYaml = ('' !== $slot['playerYaml'])
                ? $slot['playerYaml']
                : ($game?->getDefaultYaml() ?? '');

            $messageSlots[] = [
                'slotName' => $slotName,
                'playerName' => $playerName,
                'archipelagoGameName' => $archipelagoGameName,
                'playerYaml' => $playerYaml,
            ];
        }

        $session->transition(Session::STATUS_VALIDATING, $now);
        $run->setSessionId($sessionId);

        $this->sessions->flush();

        $configureSlots = array_map(
            static fn (array $slot): array => [
                'slotName' => $slot['slotName'],
                'apworldHash' => '',
                'playerYaml' => $slot['playerYaml'],
            ],
            $messageSlots,
        );

        try {
            $configureResult = $this->runnerGateway->configureSession($sessionId, $configureSlots);
        } catch (\Throwable $e) {
            $this->logger->error('personal_run.launch.configure_failed', [
                'runId' => $job->personalRunId,
                'sessionId' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            $session->transition(Session::STATUS_FAILED, $now);
            $this->sessions->flush();

            return;
        }

        if ($configureResult['valid']) {
            $session->transition(Session::STATUS_READY, $now);
            $this->sessions->flush();
        } else {
            $session->transition(Session::STATUS_FAILED, $now);
            $this->sessions->flush();
        }

        $this->logger->info('personal_run.launch.dispatched', [
            'runId' => $job->personalRunId,
            'sessionId' => $sessionId,
            'slotCount' => count($messageSlots),
        ]);
    }
}
