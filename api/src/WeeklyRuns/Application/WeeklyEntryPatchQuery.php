<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;

final readonly class WeeklyEntryPatchQuery
{
    public function __construct(
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyRunRepositoryInterface $runs,
        private UserRepositoryInterface $users,
        private string $workspaceDir,
    ) {
    }

    /**
     * @return array{outputDir: string, slotName: string|null}|null
     */
    public function forEntry(string $weeklyRunId, string $entryId, string $userId): ?array
    {
        $entry = $this->entries->findById($entryId);
        if (!$entry instanceof WeeklyEntry) {
            return null;
        }
        if ($entry->getWeeklyRunId() !== $weeklyRunId || $entry->getUserId() !== $userId) {
            return null;
        }

        $externalSessionId = $entry->getExternalSessionId();

        if (null !== $externalSessionId) {
            $user = $this->users->findById($userId);
            $slotName = $user instanceof User ? $user->getDisplayName() : 'ArchiLAN';

            return [
                'outputDir' => $this->workspaceDir.'/'.$externalSessionId.'/output',
                'slotName' => $slotName,
            ];
        }

        // Pre-launch: serve the cleaned zip from the run's generation output.
        // slotName is null because the zip is not named after the player.
        $run = $this->runs->findById($weeklyRunId);
        if (!$run instanceof WeeklyRun) {
            return null;
        }
        $seedPath = $run->getGeneratedSeedPath();
        if (null === $seedPath) {
            return null;
        }

        return [
            'outputDir' => \dirname($seedPath),
            'slotName' => null,
        ];
    }
}
