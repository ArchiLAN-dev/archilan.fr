<?php

declare(strict_types=1);

namespace App\Payments\Presentation;

use App\Payments\Domain\HelloAssoSyncLogRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:payments:cleanup-sync-log',
    description: 'Prune HelloAsso sync-log rows older than the configured retention.',
)]
final class CleanupHelloAssoSyncLogCommand extends Command
{
    public function __construct(
        private readonly HelloAssoSyncLogRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly int $helloAssoSyncLogRetentionDays,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $this->helloAssoSyncLogRetentionDays));
        $deleted = $this->repository->deleteOlderThan($threshold);

        $this->logger->info('data.cleanup_helloasso_sync_log', ['deleted' => $deleted]);

        $output->writeln(sprintf('Deleted %d HelloAsso sync-log row(s).', $deleted));

        return Command::SUCCESS;
    }
}
