<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\GameSelection\Application\Command\SweepApworldCatalog;
use App\GameSelection\Application\Message\SweepApworldCatalogMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The nightly batch of the rolling catalogue test (story 38.9), sized by APWORLD_SWEEP_BATCH_SIZE.
 */
#[AsMessageHandler]
final readonly class SweepApworldCatalogHandler
{
    public function __construct(
        private SweepApworldCatalog $sweep,
        private int $sweepBatchSize,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SweepApworldCatalogMessage $message): void
    {
        $result = $this->sweep->sweep($this->sweepBatchSize);

        if (!$result->runnerAvailable) {
            $this->logger->warning('apworld_sweep.skipped_runner_unavailable');
        }
    }
}
