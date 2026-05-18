<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Command;

use App\Identity\Application\DiscordResyncAllUsersInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:discord:resync-roles', description: 'Resync Discord roles for all linked accounts.')]
final class ResyncDiscordRolesCommand extends Command
{
    public function __construct(
        private readonly DiscordResyncAllUsersInterface $discordResyncAllUsers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count without dispatching messages.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $count = $this->discordResyncAllUsers->run($dryRun);

        if (0 === $count) {
            $output->writeln('No linked Discord accounts found.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $output->writeln(sprintf('[DRY-RUN] Would dispatch %d sync messages.', $count));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Dispatched %d sync messages.', $count));

        return Command::SUCCESS;
    }
}
