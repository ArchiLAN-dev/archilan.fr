<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation\Command;

use App\CatalogSync\Application\Command\BackfillApworldDeployedVersionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:backfill-apworld-deployed-version',
    description: 'Backfill deployed APWorld versions by matching stored hashes against GitHub release assets.',
)]
final class BackfillApworldDeployedVersionCommand extends Command
{
    public function __construct(
        private readonly BackfillApworldDeployedVersionService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve and report matches without persisting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = true === $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('<comment>Dry run - no changes will be persisted.</comment>');
        }

        $result = $this->service->backfill($dryRun);

        foreach ($result->unmatchedGames as $name) {
            $output->writeln(sprintf('  unmatched: %s', $name));
        }

        $output->writeln(sprintf(
            'APWorld deployed version backfill: %d matched, %d unmatched, %d total.',
            $result->matched,
            $result->unmatched,
            $result->total,
        ));

        if ($result->rateLimitHit) {
            $output->writeln('GitHub rate limit reached, batch stopped early.');
        }

        return Command::SUCCESS;
    }
}
