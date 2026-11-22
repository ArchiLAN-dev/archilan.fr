<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Command;

use App\Sessions\Application\Command\MeasureSessionSlots;
use App\Sessions\Application\Command\SessionSlotsMeasureOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Story 56.1: measures the slots of sessions generated before the story, or whose measure failed, from their output
 * archive. Synchronous: no messenger worker needed.
 */
#[AsCommand(name: 'app:sessions:measure-slots', description: 'Measure the size and progression share of the slots of generated sessions.')]
final class MeasureSessionSlotsCommand extends Command
{
    public function __construct(private readonly MeasureSessionSlots $measureSessionSlots)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('sessionId', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'One or more generated session ids.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getArgument('sessionId');
        $sessionIds = is_array($raw) ? array_values(array_filter($raw, is_string(...))) : [];

        $failed = 0;
        foreach ($sessionIds as $sessionId) {
            $outcome = $this->measureSessionSlots->execute($sessionId);
            if (SessionSlotsMeasureOutcome::Measured === $outcome) {
                $output->writeln(sprintf('<info>%s: measured.</info>', $sessionId));

                continue;
            }
            $output->writeln(sprintf('<comment>%s: %s.</comment>', $sessionId, $outcome->value));
            ++$failed;
        }

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }
}
