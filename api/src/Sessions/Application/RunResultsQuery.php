<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Events\Domain\Event;
use App\GameSelection\Domain\ArchipelagoGame;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\PersonalRun;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RunResultsQuery
{
    use EntityFinderTrait;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{
     *     sessionId: string,
     *     eventName: string,
     *     startedAt: string|null,
     *     finishedAt: string|null,
     *     durationSeconds: int|null,
     *     slots: list<array<string, mixed>>
     * }|null
     */
    public function execute(string $id): ?array
    {
        try {
            $session = $this->findOrFail(Session::class, $id);
        } catch (\RuntimeException) {
            return null;
        }

        if (Session::STATUS_FINISHED !== $session->getStatus()) {
            return null;
        }

        [$eventName, $isPersonalRun] = $this->resolveEventName($session);

        /** @var list<SessionSlot> $slots */
        $slots = $this->entityManager->getRepository(SessionSlot::class)
            ->findBy(['sessionId' => $id]);

        $slotData = $this->buildSlotData($slots, $session, $isPersonalRun);

        $startedAt = $session->getStartedAt();
        $finishedAt = $session->getFinishedAt();
        $durationSeconds = (null !== $startedAt && null !== $finishedAt)
            ? $finishedAt->getTimestamp() - $startedAt->getTimestamp()
            : null;

        return [
            'sessionId' => $session->getId(),
            'eventName' => $eventName,
            'startedAt' => $startedAt?->format(\DateTimeInterface::ATOM),
            'finishedAt' => $finishedAt?->format(\DateTimeInterface::ATOM),
            'durationSeconds' => $durationSeconds,
            'slots' => $slotData,
        ];
    }

    /** @return array{string, bool} */
    private function resolveEventName(Session $session): array
    {
        $event = $this->entityManager->find(Event::class, $session->getEventId());
        if ($event instanceof Event) {
            return [$event->getTitle(), false];
        }

        $pr = $this->entityManager->getRepository(PersonalRun::class)
            ->findOneBy(['id' => $session->getEventId()]);

        return [$pr instanceof PersonalRun ? $pr->getTitle() : 'Run', true];
    }

    /**
     * @param list<SessionSlot> $slots
     *
     * @return list<array<string, mixed>>
     */
    private function buildSlotData(array $slots, Session $session, bool $isPersonalRun): array
    {
        if ([] === $slots) {
            return [];
        }

        $regToUserId = $this->buildRegToUserIdMap($slots, $isPersonalRun);
        $userById = $this->loadUsers($regToUserId);
        $gameById = $this->loadGames($slots);

        $rows = [];
        foreach ($slots as $slot) {
            $userId = $regToUserId[$slot->getRegistrationId()] ?? '';
            $user = '' !== $userId ? ($userById[$userId] ?? null) : null;
            $game = $gameById[$slot->getGameId()] ?? null;

            $completionSeconds = null;
            $startedAt = $session->getStartedAt();
            $goalAt = $slot->getGoalReachedAt();
            if (null !== $goalAt && null !== $startedAt) {
                $completionSeconds = $goalAt->getTimestamp() - $startedAt->getTimestamp();
            }

            $isInvalidated = $slot->isWasReleased() && null === $goalAt;

            $rows[] = [
                'slotId' => $slot->getSlotId() ?? $slot->getId(),
                'playerName' => $user?->getDisplayName() ?? $user?->getEmail() ?? '',
                'game' => $game?->getName() ?? '',
                'checksDone' => $slot->getChecksDone(),
                'itemsReceived' => $slot->getItemsReceived(),
                'goalReachedAt' => $goalAt?->format(\DateTimeInterface::ATOM),
                'completionSeconds' => $completionSeconds,
                'wasReleased' => $slot->isWasReleased(),
                'isInvalidated' => $isInvalidated,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $aPriority = null !== $a['completionSeconds'] ? 0 : ((bool) $a['isInvalidated'] ? 2 : 1);
            $bPriority = null !== $b['completionSeconds'] ? 0 : ((bool) $b['isInvalidated'] ? 2 : 1);

            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            if (0 === $aPriority) {
                // Both have completionSeconds set (proven by priority === 0)
                return (int) $a['completionSeconds'] <=> (int) $b['completionSeconds'];
            }

            return 0;
        });

        return $rows;
    }

    /**
     * Maps each slot's registrationId to the actual userId.
     * For personal runs, registrationId IS the userId.
     * For event sessions, batch-loads Registrations.
     *
     * @param list<SessionSlot> $slots
     *
     * @return array<string, string>
     */
    private function buildRegToUserIdMap(array $slots, bool $isPersonalRun): array
    {
        $map = [];

        if ($isPersonalRun) {
            foreach ($slots as $slot) {
                $map[$slot->getRegistrationId()] = $slot->getRegistrationId();
            }

            return $map;
        }

        $registrationIds = array_unique(array_map(
            static fn (SessionSlot $s) => $s->getRegistrationId(),
            $slots,
        ));

        /** @var list<Registration> $registrations */
        $registrations = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(Registration::class, 'r')
            ->where('r.id IN (:ids)')
            ->setParameter('ids', $registrationIds)
            ->getQuery()
            ->getResult();

        foreach ($registrations as $reg) {
            $map[$reg->getId()] = $reg->getUserId();
        }

        return $map;
    }

    /**
     * @param array<string, string> $regToUserId
     *
     * @return array<string, User>
     */
    private function loadUsers(array $regToUserId): array
    {
        $userIds = array_unique(array_values($regToUserId));
        $userIds = array_filter($userIds, static fn (string $id) => '' !== $id);

        if ([] === $userIds) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.id IN (:ids)')
            ->setParameter('ids', array_values($userIds))
            ->getQuery()
            ->getResult();

        /** @var array<string, User> $userById */
        $userById = [];
        foreach ($users as $user) {
            $userById[$user->getId()] = $user;
        }

        return $userById;
    }

    /**
     * @param list<SessionSlot> $slots
     *
     * @return array<string, ArchipelagoGame>
     */
    private function loadGames(array $slots): array
    {
        $gameIds = array_unique(array_map(static fn (SessionSlot $s) => $s->getGameId(), $slots));

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

        return $gameById;
    }
}
