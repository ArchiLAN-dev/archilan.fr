<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Command;

use App\Shared\Application\Exception\ApplicationFailure;
use App\Wallet\Application\Command\AnnounceQuestsOnDiscord;
use App\Wallet\Application\Command\QuestDiscordAnnouncement;
use App\Wallet\Domain\ValueObject\QuestWeek;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Story 41.26: the week's quests on Discord by hand - a test, or a retry after a failure. */
#[AsCommand(
    name: 'app:quests:announce-discord',
    description: 'Announce a week\'s quests on Discord through the bot (the current week by default); updates the week\'s message if there is one.',
)]
final class AnnounceQuestsOnDiscordCommand extends Command
{
    public function __construct(
        private readonly AnnounceQuestsOnDiscord $announce,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('week', InputArgument::OPTIONAL, 'The week, as 2026-W41; the current week when omitted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $argument = $input->getArgument('week');
        $week = is_string($argument) && '' !== $argument ? $argument : QuestWeek::containing($this->clock->now())->key;

        try {
            $outcome = $this->announce->announce($week);
        } catch (ApplicationFailure $failure) {
            $output->writeln(sprintf('<error>%s</error>', $failure->clientMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            QuestDiscordAnnouncement::Posted === $outcome ? 'Quests of %s posted on Discord.' : 'Discord message of %s updated.',
            $week,
        ));

        return Command::SUCCESS;
    }
}
