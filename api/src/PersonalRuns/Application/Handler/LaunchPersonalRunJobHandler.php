<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Domain\PersonalRun;
use App\PersonalRuns\Domain\PersonalRunParticipant;
use App\Sessions\Application\Message\GenerateRunJob;
use App\Sessions\Application\SlotNameGenerator;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class LaunchPersonalRunJobHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private SlotNameGenerator $slotNameGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(LaunchPersonalRunJob $job): void
    {
        $run = $this->entityManager->find(PersonalRun::class, $job->personalRunId);

        if (!$run instanceof PersonalRun) {
            $this->logger->error('personal_run.launch.not_found', ['runId' => $job->personalRunId]);

            return;
        }

        /** @var list<PersonalRunParticipant> $participants */
        $participants = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(PersonalRunParticipant::class, 'p')
            ->where('p.personalRunId = :runId')
            ->setParameter('runId', $run->getId())
            ->getQuery()
            ->getResult();

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

        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(ArchipelagoGame::class, 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $gameIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, ArchipelagoGame> $gamesById */
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
