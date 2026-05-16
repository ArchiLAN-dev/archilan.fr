<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use App\Sessions\Application\Message\GenerateRunJob;
use App\Sessions\Application\SlotNameGenerator;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class LaunchPersonalRunJobHandler
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private SlotNameGenerator $slotNameGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LaunchPersonalRunJob $job): void
    {
        try {
            $run = $this->findOrFail(Run::class, $job->personalRunId);
        } catch (\RuntimeException) {
            $this->logger->error('personal_run.launch.not_found', ['runId' => $job->personalRunId]);

            return;
        }

        /** @var list<RunParticipant> $participants */
        $participants = $this->entityManager->getRepository(RunParticipant::class)->findBy(['runId' => $run->getId()]);

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

        $userIds = array_unique(array_column($slotsForSession, 'userId'));
        $gameIds = array_unique(array_column($slotsForSession, 'gameId'));

        /** @var list<User> $users */
        $users = $this->entityManager->getRepository(User::class)->findBy(['id' => $userIds]);

        /** @var array<string, User> $usersById */
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        /** @var list<Game> $games */
        $games = $this->entityManager->getRepository(Game::class)->findBy(['id' => $gameIds]);

        /** @var array<string, Game> $gamesById */
        $gamesById = [];
        foreach ($games as $game) {
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
        $this->entityManager->persist($session);

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
            $this->entityManager->persist($sessionSlot);

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

        $this->entityManager->flush();

        $this->messageBus->dispatch(new GenerateRunJob($sessionId, 'validate', $messageSlots));

        $this->logger->info('personal_run.launch.dispatched', [
            'runId' => $job->personalRunId,
            'sessionId' => $sessionId,
            'slotCount' => count($messageSlots),
        ]);
    }
}
