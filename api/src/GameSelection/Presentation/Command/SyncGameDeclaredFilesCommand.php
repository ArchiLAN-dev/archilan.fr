<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Command;

use App\GameSelection\Application\Command\SyncGameDeclaredFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:games:sync-declared-files', description: 'Copy onto each game the files its world declares, a ROM most often (story 44.3).')]
final class SyncGameDeclaredFilesCommand extends Command
{
    public function __construct(private readonly SyncGameDeclaredFiles $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reintrospect', null, InputOption::VALUE_NONE, 'Re-read every served apworld first (once after deploying story 44.3: older introspections carry no file). Slow.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Typically right after a new Archipelago image is deployed: its own worlds may declare new files.
        $report = $this->sync->syncAll(true === $input->getOption('reintrospect'));
        if (!$report->runnerAvailable) {
            $output->writeln('Runner unavailable: nothing was changed.');

            return Command::FAILURE;
        }
        $output->writeln(sprintf('%d game(s) updated, %d skipped.', $report->updated, $report->skipped));

        return Command::SUCCESS;
    }
}
