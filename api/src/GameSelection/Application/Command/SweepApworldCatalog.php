<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Query\ServedApworldsQueryInterface;
use App\GameSelection\Domain\Repository\ApworldCandidateRepositoryInterface;
use App\GameSelection\Domain\Service\CatalogSweepPlanner;
use App\GameSelection\Domain\ValueObject\SweepCandidate;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use Psr\Log\LoggerInterface;

/**
 * Relaunches the test of a small batch of served apworlds (story 38.9): an apworld is otherwise tested
 * once, at import, and nothing notices when a new Archipelago image breaks it, or when an unlucky seed
 * let a flaky one through. The batch is picked by {@see CatalogSweepPlanner}, the verdicts reach the
 * incident reconciliation like any other.
 *
 * Left alone: a disabled game, a game whose candidate is in test (story 38.6), a verdict an admin forced,
 * a test still running, a skipped one (no template to test with), an apworld the orchestrator does not
 * list. Without the image in use or without verdicts, nothing is launched: there is nothing to rank by.
 *
 * No parallelism here: the tests queue on the orchestrator, whose own limit bounds the real load.
 */
final readonly class SweepApworldCatalog
{
    public const int MIN_BATCH_SIZE = 1;
    public const int MAX_BATCH_SIZE = 200;

    public function __construct(
        private ServedApworldsQueryInterface $servedApworlds,
        private RunnerGatewayInterface $runnerGateway,
        private ApworldCandidateRepositoryInterface $candidates,
        private LoggerInterface $logger,
    ) {
    }

    public function sweep(int $batchSize): SweepApworldCatalogResult
    {
        $clamped = max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $batchSize));
        if ($clamped !== $batchSize) {
            $this->logger->warning('apworld_sweep.batch_size_clamped', ['requested' => $batchSize, 'used' => $clamped]);
        }

        $runtime = $this->runnerGateway->fetchRuntime();
        $verdicts = null !== $runtime ? $this->runnerGateway->fetchApworldPreflights() : [];
        if (null === $runtime || [] === $verdicts) {
            return new SweepApworldCatalogResult(false);
        }

        $inTest = [];
        foreach ($this->candidates->findAllTesting() as $candidate) {
            $inTest[$candidate->getGameId()] = true;
        }

        $sweepCandidates = [];
        foreach ($this->servedApworlds->servedApworlds() as $served) {
            $verdict = $verdicts[$served->apworldHash] ?? null;
            $status = $verdict['status'] ?? null;
            $ran = \in_array($status, ['passed', 'failed'], true) && '' !== ($verdict['checkedAt'] ?? '');

            $sweepCandidates[] = new SweepCandidate(
                $served->gameId,
                $served->apworldHash,
                $verdict['image'] ?? null,
                $verdict['imageId'] ?? null,
                $ran ? new \DateTimeImmutable($verdict['checkedAt']) : null,
                null === $verdict
                    || $served->disabled
                    || isset($inTest[$served->gameId])
                    || true === $verdict['overridden']
                    || \in_array($status, ['pending', 'skipped'], true),
            );
        }

        $launched = [];
        foreach (CatalogSweepPlanner::plan($sweepCandidates, $runtime['apImage'], $runtime['apImageId'], $clamped) as $hash) {
            if ($this->runnerGateway->runApworldPreflight($hash)) {
                $launched[] = $hash;
            } else {
                $this->logger->warning('apworld_sweep.preflight_not_started', ['hash' => $hash]);
            }
        }

        $this->logger->info('apworld_sweep.launched', ['image' => $runtime['apImage'], 'count' => \count($launched)]);

        return new SweepApworldCatalogResult(true, $launched, $runtime['apImage']);
    }
}
