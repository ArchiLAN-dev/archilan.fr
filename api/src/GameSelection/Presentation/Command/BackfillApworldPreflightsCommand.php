<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Command;

use App\GameSelection\Application\Command\BackfillApworldPreflights;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:apworlds:preflight-backfill', description: 'Queue the preflight solo test generation for uploaded apworlds that never ran one (story 9.38).')]
final class BackfillApworldPreflightsCommand extends Command
{
    public function __construct(
        private readonly BackfillApworldPreflights $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Re-run the check for every apworld, even those with a verdict.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->service->run(true === $input->getOption('all'));

        $output->writeln(sprintf(
            'Apworld preflight backfill: %d apworld(s), %d check(s) queued, %d skipped (already checked), %d runner error(s).',
            $result->total,
            $result->requested,
            $result->skipped,
            $result->failed,
        ));

        return $result->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
