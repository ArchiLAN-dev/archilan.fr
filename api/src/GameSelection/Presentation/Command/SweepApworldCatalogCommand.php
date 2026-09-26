<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Command;

use App\GameSelection\Application\Command\SweepApworldCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:apworld-sweep:run', description: 'Retest a batch of served apworlds, those not yet tested on the current image first (story 38.9).')]
final class SweepApworldCatalogCommand extends Command
{
    public function __construct(
        private readonly SweepApworldCatalog $sweep,
        private readonly int $sweepBatchSize,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'How many apworlds to retest (1 to 200); the nightly setting by default.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Typically right after a new Archipelago image is deployed, to validate it faster.
        $batch = $input->getOption('batch');
        $result = $this->sweep->sweep(is_numeric($batch) ? (int) $batch : $this->sweepBatchSize);

        if (!$result->runnerAvailable) {
            $output->writeln('Runner unavailable: nothing launched.');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('%d apworld test(s) launched on %s.', \count($result->launchedApworldHashes), $result->currentImage ?? '?'));

        return Command::SUCCESS;
    }
}
