<?php

declare(strict_types=1);

namespace App\Events\Presentation;

use App\Events\Domain\EventPrivateAccessLogRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:events:cleanup-access-log',
    description: 'Prune event private-access-log rows older than the configured retention.',
)]
final class CleanupEventPrivateAccessLogCommand extends Command
{
    public function __construct(
        private readonly EventPrivateAccessLogRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly int $eventAccessLogRetentionDays,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $this->eventAccessLogRetentionDays));
        $deleted = $this->repository->deleteOlderThan($threshold);

        $this->logger->info('data.cleanup_event_private_access_log', ['deleted' => $deleted]);

        $output->writeln(sprintf('Deleted %d event private-access-log row(s).', $deleted));

        return Command::SUCCESS;
    }
}
