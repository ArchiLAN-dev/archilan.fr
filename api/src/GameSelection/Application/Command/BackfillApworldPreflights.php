<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 9.38 AC5 backfill: sweep the uploaded apworld pool and trigger the preflight solo
 * test generation for every apworld that never ran one (the pool predates the check).
 * With $all, every apworld is re-checked regardless of its current verdict.
 *
 * The runs are asynchronous on the orchestrator: this command only queues them.
 */
final readonly class BackfillApworldPreflights
{
    public function __construct(
        private RunnerGatewayInterface $runnerGateway,
        private ServedApworldsQueryInterface $servedApworlds,
        private LoggerInterface $logger,
    ) {
    }

    public function run(bool $all = false): BackfillApworldPreflightsResult
    {
        $verdicts = $this->runnerGateway->fetchApworldPreflights();
        // Story 38.30: a meta-game served by a game is tested with a partner player.
        $withPartner = [];
        foreach ($this->servedApworlds->servedApworlds() as $served) {
            $withPartner[$served->apworldHash] = $served->testWithPartner;
        }

        $requested = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($verdicts as $hash => $verdict) {
            if (!$all && '' !== $verdict['status']) {
                ++$skipped;
                continue;
            }
            // Review of epic 44: like the rolling test, never replace a pass obtained with the admin's file by a run
            // that cannot have it.
            if ('passed' === $verdict['status'] && true === ($verdict['withRequiredFiles'] ?? false)) {
                ++$skipped;
                continue;
            }
            if ($this->runnerGateway->runApworldPreflight($hash, [], $withPartner[$hash] ?? false)) {
                ++$requested;
            } else {
                ++$failed;
            }
        }

        $this->logger->info('apworlds.preflight_backfill', [
            'requested' => $requested,
            'skipped' => $skipped,
            'failed' => $failed,
            'all' => $all,
        ]);

        return new BackfillApworldPreflightsResult(
            total: count($verdicts),
            requested: $requested,
            skipped: $skipped,
            failed: $failed,
        );
    }
}
