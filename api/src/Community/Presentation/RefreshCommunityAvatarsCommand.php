<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\RefreshCommunityAvatars;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'community:avatars:refresh',
    description: 'Resolve & cache stale/missing community avatars (Discord/Steam), off the request path.',
)]
final class RefreshCommunityAvatarsCommand extends Command
{
    public function __construct(private readonly RefreshCommunityAvatars $refreshAvatars)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $refreshed = $this->refreshAvatars->refreshStale();
        $output->writeln(sprintf('Refreshed %d avatar(s).', $refreshed));

        return Command::SUCCESS;
    }
}
