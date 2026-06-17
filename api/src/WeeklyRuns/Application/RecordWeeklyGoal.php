<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class RecordWeeklyGoal
{
    public function __construct(
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyRunRepositoryInterface $runs,
        private UserRepositoryInterface $users,
        private HubInterface $mercureHub,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{entryId: string}|null null when sessionId not found (caller returns 200)
     */
    public function execute(
        string $externalSessionId,
        int $checksTotal,
        int $itemsTotal,
        \DateTimeImmutable $goalReachedAt,
    ): ?array {
        $entry = $this->entries->findByExternalSessionId($externalSessionId);

        if (!$entry instanceof WeeklyEntry) {
            $this->logger->warning('weekly_goal_callback.entry_not_found', [
                'externalSessionId' => $externalSessionId,
            ]);

            return null;
        }

        if (null !== $entry->getGoalReachedAt()) {
            return ['entryId' => $entry->getId()];
        }

        $run = $this->runs->findById($entry->getWeeklyRunId());
        if (!$run instanceof WeeklyRun) {
            $this->logger->warning('weekly_goal_callback.run_not_found', [
                'weeklyRunId' => $entry->getWeeklyRunId(),
            ]);

            return null;
        }

        // Time the run from when the player launched their game (story 23.14), not from the run's
        // global generation time - so waiting before starting doesn't inflate the leaderboard time.
        // launchedAt == the Session's startedAt (17.13) and is stable across relaunch; fall back to
        // the run start only defensively (a recorded goal always implies a prior launch).
        $startedAt = $entry->getLaunchedAt() ?? $run->getStartedAt();
        $completionTimeSeconds = max(0, $goalReachedAt->getTimestamp() - $startedAt->getTimestamp());
        $entry->recordGoal($goalReachedAt, $completionTimeSeconds, $checksTotal, $itemsTotal);
        $this->entries->flush();

        $user = $this->users->findById($entry->getUserId());
        $displayName = $user instanceof User ? $user->getDisplayName() : null;

        $payload = [
            'event' => 'goal_reached',
            'entryId' => $entry->getId(),
            'userId' => $entry->getUserId(),
            'displayName' => $displayName,
            'completionTimeSeconds' => $completionTimeSeconds,
            'checksTotal' => $checksTotal,
            'itemsTotal' => $itemsTotal,
            'goalReachedAt' => $goalReachedAt->format(\DateTimeInterface::ATOM),
        ];

        try {
            $this->mercureHub->publish(new Update(
                topics: [sprintf('weekly-runs/%s/leaderboard', $entry->getWeeklyRunId())],
                data: json_encode($payload, JSON_THROW_ON_ERROR),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Mercure publish failed for weekly leaderboard', [
                'weeklyRunId' => $entry->getWeeklyRunId(),
                'error' => $e->getMessage(),
            ]);
        }

        return ['entryId' => $entry->getId()];
    }
}
