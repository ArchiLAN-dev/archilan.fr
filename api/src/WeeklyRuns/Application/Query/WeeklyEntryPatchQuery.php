<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Repository\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\Repository\WeeklyRunRepositoryInterface;

final readonly class WeeklyEntryPatchQuery
{
    public function __construct(
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyRunRepositoryInterface $runs,
        private string $workspaceDir,
    ) {
    }

    /**
     * Returns either a durable context (post-launch, orchestrator-managed session - the run's frozen
     * MinIO output archive) or a local filesystem context (legacy Docker sessions).
     *
     * @return array{type: 'durable', outputKey: string}
     *                                                   | array{type: 'local', outputDir: string, slotName: string|null, sessionId: string|null}
     *                                                   | null
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
            if (null !== $entry->getBridgePort()) {
                // Orchestrator session: read the run's DURABLE MinIO output archive, never the live
                // bridge port. The host port is freed on stop and reused by other sessions, so a
                // finished entry's stale bridgePort would otherwise resolve to another running party's
                // bridge and leak their patch files (#262). The run output key is frozen and unique.
                $run = $this->runs->findById($weeklyRunId);
                $outputKey = $run instanceof WeeklyRun ? $run->getGeneratedOutputKey() : null;
                if (null === $outputKey) {
                    return null;
                }

                return ['type' => 'durable', 'outputKey' => $outputKey];
            }

            // Legacy Docker-based session: files are on the local filesystem, keyed by the frozen
            // session id (no port-reuse hazard).
            $outputDir = $this->workspaceDir.'/'.$externalSessionId.'/output';

            return ['type' => 'local', 'outputDir' => $outputDir, 'slotName' => null, 'sessionId' => $externalSessionId];
        }

        // Pre-launch: only possible with legacy Docker generator which writes a real
        // seed file path. The orchestrator stores a hash, not a path.
        $run = $this->runs->findById($weeklyRunId);
        if (!$run instanceof WeeklyRun) {
            return null;
        }
        $seedPath = $run->getGeneratedOutputKey();
        if (null === $seedPath || !is_file($seedPath)) {
            return null;
        }

        // Pas de session : le lien public ne peut pas être signé pour ce dossier (story 16.16).
        return ['type' => 'local', 'outputDir' => \dirname($seedPath), 'slotName' => null, 'sessionId' => null];
    }
}
