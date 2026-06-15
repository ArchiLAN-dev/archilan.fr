<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\BackfillSteamAppIds;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:games:backfill-steam-app-ids', description: 'Resolve Steam appids from IGDB for games that have an igdbId but no steamAppId (story 28.1).')]
final class BackfillSteamAppIdsCommand extends Command
{
    public function __construct(
        private readonly BackfillSteamAppIds $service,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->service->run();

        $output->writeln(sprintf(
            'Steam appid backfill: %d game(s) processed, %d updated.',
            $result['processed'],
            $result['updated'],
        ));

        return Command::SUCCESS;
    }
}
