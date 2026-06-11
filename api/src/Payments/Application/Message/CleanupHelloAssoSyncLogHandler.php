<?php

declare(strict_types=1);

namespace App\Payments\Application\Message;

use App\Payments\Domain\HelloAssoSyncLogRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanupHelloAssoSyncLogHandler
{
    public function __construct(
        private HelloAssoSyncLogRepositoryInterface $repository,
        private LoggerInterface $logger,
        private int $helloAssoSyncLogRetentionDays,
    ) {
    }

    public function __invoke(CleanupHelloAssoSyncLogMessage $message): void
    {
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $this->helloAssoSyncLogRetentionDays));
        $deleted = $this->repository->deleteOlderThan($threshold);

        $this->logger->info('data.cleanup_helloasso_sync_log', ['deleted' => $deleted]);
    }
}
