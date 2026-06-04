<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Events\Domain\Event;
use App\Events\Domain\EventRepositoryInterface;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Registrations\Domain\Registration;
use App\Registrations\Domain\RegistrationRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\Sessions\Domain\SessionSlot;
use App\Sessions\Domain\SessionSlotRepositoryInterface;

final readonly class RunResultsQuery
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private RunRepositoryInterface $runs,
        private EventRepositoryInterface $events,
        private RegistrationRepositoryInterface $registrations,
        private UserRepositoryInterface $users,
        private GameRepositoryInterface $games,
    ) {
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
        $session = $this->sessions->findById($id);
        if (!$session instanceof Session) {
            return null;
        }

        if (Session::STATUS_FINISHED !== $session->getStatus()) {
            return null;
        }

        [$eventName, $isPersonalRun] = $this->resolveEventName($session);

        $slotsList = $this->slots->findBySessionId($id);

        $slotData = $this->buildSlotData($slotsList, $session, $isPersonalRun);

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
        $event = $this->events->findById($session->getEventId());
        if ($event instanceof Event) {
            return [$event->getTitle(), false];
        }

        $run = $this->runs->findById($session->getEventId());

        return [$run instanceof Run ? $run->getTitle() : 'Run', true];
    }

    /**
     * @param list<SessionSlot> $slotsList
     *
     * @return list<array<string, mixed>>
     */
    private function buildSlotData(array $slotsList, Session $session, bool $isPersonalRun): array
    {
        if ([] === $slotsList) {
            return [];
        }

        $regToUserId = $this->buildRegToUserIdMap($slotsList, $isPersonalRun);
        $userById = $this->loadUsers($regToUserId);
        $gameById = $this->loadGames($slotsList);

        $rows = [];
        foreach ($slotsList as $slot) {
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
                return (int) $a['completionSeconds'] <=> (int) $b['completionSeconds'];
            }

            return 0;
        });

        return $rows;
    }

    /**
     * @param list<SessionSlot> $slotsList
     *
     * @return array<string, string>
     */
    private function buildRegToUserIdMap(array $slotsList, bool $isPersonalRun): array
    {
        $map = [];

        if ($isPersonalRun) {
            foreach ($slotsList as $slot) {
                $map[$slot->getRegistrationId()] = $slot->getRegistrationId();
            }

            return $map;
        }

        $registrationIds = array_values(array_unique(array_map(
            static fn (SessionSlot $s) => $s->getRegistrationId(),
            $slotsList,
        )));

        /** @var list<Registration> $regList */
        $regList = $this->registrations->findBy(['id' => $registrationIds]);

        foreach ($regList as $reg) {
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
        $userIds = array_values(array_filter(array_values($regToUserId), static fn (string $id) => '' !== $id));
        $userIds = array_values(array_unique($userIds));

        if ([] === $userIds) {
            return [];
        }

        $usersList = $this->users->findByIds($userIds);

        /** @var array<string, User> $userById */
        $userById = [];
        foreach ($usersList as $user) {
            $userById[$user->getId()] = $user;
        }

        return $userById;
    }

    /**
     * @param list<SessionSlot> $slotsList
     *
     * @return array<string, Game>
     */
    private function loadGames(array $slotsList): array
    {
        $gameIds = array_values(array_unique(array_map(static fn (SessionSlot $s) => $s->getGameId(), $slotsList)));

        $gamesList = $this->games->findByIds($gameIds);

        /** @var array<string, Game> $gameById */
        $gameById = [];
        foreach ($gamesList as $game) {
            $gameById[$game->getId()] = $game;
        }

        return $gameById;
    }
}
