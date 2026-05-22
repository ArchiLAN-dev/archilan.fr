<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Domain\RefreshTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:auth:cleanup-refresh-tokens', description: 'Prune stale refresh token records.')]
final class CleanupRefreshTokensCommand extends Command
{
    public function __construct(
        private readonly RefreshTokenRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->repository->deleteStale(new \DateTimeImmutable());

        $this->logger->info('auth.cleanup_refresh_tokens', ['deleted' => $deleted]);

        $output->writeln(sprintf('Deleted %d stale refresh token(s).', $deleted));

        return Command::SUCCESS;
    }
}
