<?php

declare(strict_types=1);

namespace App\Events\Application\Message;

use App\Events\Domain\EventPrivateAccessLogRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CleanupEventPrivateAccessLogHandler
{
    public function __construct(
        private EventPrivateAccessLogRepositoryInterface $repository,
        private LoggerInterface $logger,
        private int $eventAccessLogRetentionDays,
    ) {
    }

    public function __invoke(CleanupEventPrivateAccessLogMessage $message): void
    {
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $this->eventAccessLogRetentionDays));
        $deleted = $this->repository->deleteOlderThan($threshold);

        $this->logger->info('data.cleanup_event_private_access_log', ['deleted' => $deleted]);
    }
}
