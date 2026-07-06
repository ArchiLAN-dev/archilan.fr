<?php

declare(strict_types=1);

namespace App\Payments\Application\Handler;

use App\Payments\Application\Message\CleanupHelloAssoSyncLogMessage;
use App\Payments\Domain\Repository\HelloAssoSyncLogRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanupHelloAssoSyncLogHandler
{
    public function __construct(
        private HelloAssoSyncLogRepositoryInterface $repository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private int $helloAssoSyncLogRetentionDays,
    ) {
    }

    public function __invoke(CleanupHelloAssoSyncLogMessage $message): void
    {
        $threshold = $this->clock->now()->modify(sprintf('-%d days', $this->helloAssoSyncLogRetentionDays));
        $deleted = $this->repository->deleteOlderThan($threshold);

        $this->logger->info('data.cleanup_helloasso_sync_log', ['deleted' => $deleted]);
    }
}
