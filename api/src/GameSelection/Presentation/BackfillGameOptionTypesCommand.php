<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\BackfillGameOptionTypes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:games:backfill-option-types', description: 'Re-fetch introspected range bounds for existing apworld games (story 9.25).')]
final class BackfillGameOptionTypesCommand extends Command
{
    public function __construct(
        private readonly BackfillGameOptionTypes $service,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->service->run();

        $output->writeln(sprintf(
            'Option types backfill: %d apworld game(s) processed, %d updated.',
            $result['processed'],
            $result['updated'],
        ));

        return Command::SUCCESS;
    }
}
