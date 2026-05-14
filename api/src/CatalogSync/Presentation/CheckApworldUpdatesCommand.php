<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation;

use App\CatalogSync\Application\CheckApworldUpdatesService;
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

        $output->writeln(sprintf('Checked %d APWorld(s).', $result['checked']));

        if ($result['rateLimitHit']) {
            $output->writeln('GitHub rate limit reached, batch stopped early.');
        }

        return Command::SUCCESS;
    }
}
