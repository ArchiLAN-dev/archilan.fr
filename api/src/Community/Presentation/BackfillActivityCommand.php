<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\BackfillActivity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'community:activity:backfill',
    description: 'Reconstruct the activity feed from existing run history (idempotent, off the request path).',
)]
final class BackfillActivityCommand extends Command
{
    public function __construct(private readonly BackfillActivity $backfill)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $recorded = $this->backfill->run();
        $output->writeln(sprintf('Recorded %d new activity entr%s.', $recorded, 1 === $recorded ? 'y' : 'ies'));

        return Command::SUCCESS;
    }
}
