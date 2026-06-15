<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\BackfillGamePlatforms;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:games:backfill-platforms', description: 'Resolve IGDB platforms for games that have an igdbId but no platforms (story 28.6).')]
final class BackfillGamePlatformsCommand extends Command
{
    public function __construct(
        private readonly BackfillGamePlatforms $service,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->service->run();

        $output->writeln(sprintf(
            'Platforms backfill: %d game(s) processed, %d updated.',
            $result['processed'],
            $result['updated'],
        ));

        return Command::SUCCESS;
    }
}
