<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Handler;

use App\Community\Application\Support\Notifier;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Message\NotifyRunGamesChosenJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tells the owner which games a player settled on (story 43.28), once the player stopped changing them for
 * {@see NotifyRunGamesChosenJob::SETTLE_MS}: a job whose list is no longer the player's stays quiet, the later one
 * speaks. Nothing is said once the run is launched, nor for the owner's own games.
 */
#[AsMessageHandler]
final readonly class NotifyRunGamesChosenJobHandler
{
    public const string NOTIFICATION_TYPE = 'run_games_chosen';

    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private GameRepositoryInterface $games,
        private UserRepositoryInterface $users,
        private Notifier $notifier,
    ) {
    }

    public function __invoke(NotifyRunGamesChosenJob $job): void
    {
        $run = $this->runs->findById($job->runId);
        if (!$run instanceof Run || $run->isOwnedBy($job->userId) || $run->isLockedForEditing()) {
            return;
        }
        $participant = $this->participants->findByRunAndUser($job->runId, $job->userId);
        if (!$participant instanceof RunParticipant || self::gameIdsOf($participant) !== $job->gameIds) {
            return;
        }

        $names = [];
        foreach ($this->games->findByIds($job->gameIds) as $game) {
            $names[$game->getId()] = $game->getName();
        }
        $member = $this->users->findById($job->userId);

        $this->notifier->notify($run->getOwnerId(), self::NOTIFICATION_TYPE, [
            'fromUserId' => $job->userId,
            'playerName' => $member instanceof User ? $member->getDisplayName() : null,
            'runId' => $run->getId(),
            'runTitle' => $run->getTitle(),
            // In the order the player has them, a game counted once.
            'games' => array_values(array_unique(array_values(array_filter(
                array_map(static fn (array $slot): ?string => $names[$slot['gameId']] ?? null, $participant->getGameSlots()),
                is_string(...),
            )))),
        ]);
    }

    /**
     * @return list<string> the player's game ids, sorted (a game held twice appears twice)
     */
    public static function gameIdsOf(RunParticipant $participant): array
    {
        $ids = array_map(static fn (array $slot): string => $slot['gameId'], $participant->getGameSlots());
        sort($ids);

        return $ids;
    }
}
