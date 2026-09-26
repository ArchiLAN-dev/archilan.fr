<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation\Command;

use App\CatalogSync\Application\Command\CheckApworldUpdatesService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:check-apworld-updates', description: 'Check GitHub for APWorld version updates.')]
final class CheckApworldUpdatesCommand extends Command
{
    public function __construct(
        private readonly CheckApworldUpdatesService $service,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->service->checkAll();

        $output->writeln(sprintf('Checked %d APWorld(s).', $result->checked));

        if ($result->failed > 0) {
            $output->writeln(sprintf('%d check(s) failed (network or unreadable answer) and were skipped, see the logs.', $result->failed));
        }

        if ($result->rateLimitHit) {
            $output->writeln('GitHub rate limit reached, batch stopped early.');
        }

        $output->writeln(sprintf('%d update(s) available:', \count($result->updatesAvailable)));
        foreach ($result->updatesAvailable as $update) {
            $output->writeln(sprintf('  - %s -> %s', $update->gameName, $update->latestTag));
        }

        return Command::SUCCESS;
    }
}
