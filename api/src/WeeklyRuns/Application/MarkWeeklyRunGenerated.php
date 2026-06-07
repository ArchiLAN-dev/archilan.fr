<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Marks a weekly run as generated once the orchestrator signals completion
 * (`session.generated` webhook for a "weekly-gen-{runId}" session). Stores the
 * MinIO output key so the run becomes launchable, then cleans up the throwaway
 * generator session. Idempotent: a duplicate webhook is a no-op.
 */
final readonly class MarkWeeklyRunGenerated
{
    public function __construct(
        private WeeklyRunRepositoryInterface $runs,
        private WeeklyRunnerGatewayInterface $gateway,
        private LoggerInterface $logger,
    ) {
    }

    public function execute(string $weeklyRunId, string $outputKey): void
    {
        if ('' === $outputKey) {
            $this->logger->warning('weekly_runs.mark_generated.missing_output_key', ['weeklyRunId' => $weeklyRunId]);

            return;
        }

        $run = $this->runs->findById($weeklyRunId);
        if (!$run instanceof WeeklyRun) {
            $this->logger->warning('weekly_runs.mark_generated.run_not_found', ['weeklyRunId' => $weeklyRunId]);

            return;
        }

        // Idempotent: a duplicate or retried webhook must not re-process.
        if (null !== $run->getGeneratedOutputKey()) {
            return;
        }

        $run->markGenerated($outputKey);
        $this->runs->flush();

        // Best-effort cleanup of the throwaway generator session (output already durable in MinIO).
        try {
            $this->gateway->terminate(WeeklyRunGeneratorInterface::GENERATOR_SESSION_PREFIX.$weeklyRunId);
        } catch (\Throwable $e) {
            $this->logger->warning('weekly_runs.mark_generated.cleanup_failed', [
                'weeklyRunId' => $weeklyRunId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
