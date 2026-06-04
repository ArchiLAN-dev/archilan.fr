<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;

final readonly class WeeklyEntryPatchQuery
{
    public function __construct(
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyRunRepositoryInterface $runs,
        private string $workspaceDir,
    ) {
    }

    /**
     * Returns either a bridge context (post-launch, orchestrator-managed session)
     * or a local filesystem context (legacy Docker sessions).
     *
     * @return array{type: 'bridge', bridgePort: int}
     *                                                | array{type: 'local', outputDir: string, slotName: string|null}
     *                                                | null
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
            $bridgePort = $entry->getBridgePort();
            if (null !== $bridgePort) {
                // Orchestrator-based session: files live in a Docker volume accessible
                // only through the bridge's /output endpoint.
                return ['type' => 'bridge', 'bridgePort' => $bridgePort];
            }

            // Legacy Docker-based session: files are on the local filesystem.
            $outputDir = $this->workspaceDir.'/'.$externalSessionId.'/output';

            return ['type' => 'local', 'outputDir' => $outputDir, 'slotName' => null];
        }

        // Pre-launch: only possible with legacy Docker generator which writes a real
        // seed file path. The orchestrator stores a hash, not a path.
        $run = $this->runs->findById($weeklyRunId);
        if (!$run instanceof WeeklyRun) {
            return null;
        }
        $seedPath = $run->getGeneratedSeedPath();
        if (null === $seedPath || !is_file($seedPath)) {
            return null;
        }

        return ['type' => 'local', 'outputDir' => \dirname($seedPath), 'slotName' => null];
    }
}
