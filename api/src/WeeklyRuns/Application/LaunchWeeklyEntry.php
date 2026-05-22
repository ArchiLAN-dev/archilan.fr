<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class LaunchWeeklyEntry
{
    public function __construct(
        private WeeklyRunRepositoryInterface $runs,
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyRunnerGatewayInterface $gateway,
        private ClockInterface $clock,
        private string $workspaceDir,
    ) {
    }

    /**
     * @return array{entryId: string, externalSessionId: string, connectionInfo: array{host: string, port: int, password: string|null}}
     */
    public function execute(string $weeklyRunId, string $entryId, string $userId): array
    {
        $run = $this->runs->findById($weeklyRunId);
        if (!$run instanceof WeeklyRun) {
            throw new \DomainException('run_not_found');
        }

        if (WeeklyRun::STATUS_ACTIVE !== $run->getStatus()) {
            throw new \DomainException('run_not_active');
        }

        $seedFilePath = $run->getGeneratedSeedPath();
        if (null === $seedFilePath || !is_file($seedFilePath)) {
            throw new \DomainException('run_not_generated');
        }

        $entry = $this->entries->findById($entryId);
        if (!$entry instanceof WeeklyEntry) {
            throw new \DomainException('entry_not_found');
        }

        if ($entry->getWeeklyRunId() !== $weeklyRunId || $entry->getUserId() !== $userId) {
            throw new \DomainException('forbidden');
        }

        if (null !== $entry->getExternalSessionId()) {
            throw new \DomainException('session_already_started');
        }

        // Copy the seed file and yamls into the entry's workspace so the runner can launch
        // an isolated server instance for this player from the pre-generated world.
        $entryOutputDir = $this->workspaceDir.'/'.$entryId.'/output';
        if (!is_dir($entryOutputDir)) {
            mkdir($entryOutputDir, 0755, true);
        }
        $entrySeeedPath = $entryOutputDir.'/'.basename($seedFilePath);
        copy($seedFilePath, $entrySeeedPath);

        // Copy yamls from the run's workspace so reachable.py can rebuild the MultiWorld.
        $runYamlsDir = $this->workspaceDir.'/'.$weeklyRunId.'/yamls';
        $entryYamlsDir = $this->workspaceDir.'/'.$entryId.'/yamls';
        if (is_dir($runYamlsDir) && !is_dir($entryYamlsDir)) {
            mkdir($entryYamlsDir, 0755, true);
            foreach (glob($runYamlsDir.'/*.yaml') ?: [] as $yamlFile) {
                copy($yamlFile, $entryYamlsDir.'/'.basename($yamlFile));
            }
        }

        $result = $this->gateway->launchFromSeed($entryId, $entrySeeedPath);

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $bridgePort = $result['bridgePort'];
        $entry->launch($result['externalSessionId'], $now, $result['connectionInfo'], $bridgePort);

        try {
            $this->entries->flush();
        } catch (\Throwable $e) {
            try {
                $this->gateway->terminate($result['externalSessionId']);
            } catch (\Throwable) {
            }

            throw $e;
        }

        return [
            'entryId' => $entryId,
            'externalSessionId' => $result['externalSessionId'],
            'connectionInfo' => $result['connectionInfo'],
        ];
    }
}
