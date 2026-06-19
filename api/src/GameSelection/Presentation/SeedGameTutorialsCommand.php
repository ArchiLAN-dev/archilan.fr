<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\SeedGameTutorials;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:games:seed-tutorials', description: 'Seed a draft install tutorial for every game that has none yet (story 31.1).')]
final class SeedGameTutorialsCommand extends Command
{
    public function __construct(
        private readonly SeedGameTutorials $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Reseed all games, even those that already have authored steps.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->service->run((bool) $input->getOption('force'));

        $output->writeln(sprintf(
            'Tutorials seed: %d game(s) processed, %d seeded.',
            $result['processed'],
            $result['seeded'],
        ));

        return Command::SUCCESS;
    }
}
