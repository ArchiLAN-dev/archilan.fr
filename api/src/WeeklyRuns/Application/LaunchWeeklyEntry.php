<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class LaunchWeeklyEntry
{
    public function __construct(
        private WeeklyRunRepositoryInterface $runs,
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyTemplateRepositoryInterface $templates,
        private WeeklyRunnerGatewayInterface $gateway,
        private ClockInterface $clock,
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

        $outputKey = $run->getGeneratedOutputKey();
        if (null === $outputKey) {
            throw new \DomainException('run_not_generated');
        }

        $template = $this->templates->findById($run->getTemplateId());
        if (null === $template) {
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

        // The run's generated world is already built; launch reuses it (no regeneration).
        $result = $this->gateway->launchEntry($entryId, $outputKey);

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $entry->launch($result['externalSessionId'], $now, $result['connectionInfo'], $result['bridgePort']);

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
