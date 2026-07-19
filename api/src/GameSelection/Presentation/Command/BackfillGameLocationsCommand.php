<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Command;

use App\GameSelection\Application\Command\BackfillGameLocations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:games:backfill-locations', description: 'Re-fetch introspected static location names for existing apworld games (story 4.14).')]
final class BackfillGameLocationsCommand extends Command
{
    public function __construct(
        private readonly BackfillGameLocations $service,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->service->run();

        $output->writeln(sprintf(
            'Location names backfill: %d apworld game(s) processed, %d updated.',
            $result->processed,
            $result->updated,
        ));

        return Command::SUCCESS;
    }
}
