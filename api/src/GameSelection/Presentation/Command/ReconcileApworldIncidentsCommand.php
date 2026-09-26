<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Command;

use App\GameSelection\Application\Command\ReconcileApworldIncidents;
use App\GameSelection\Application\Support\ApworldIncidentAlertDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:apworlds:incidents-reconcile', description: 'Open and resolve apworld incidents from the orchestrator test verdicts, and alert on them (stories 38.1, 38.2).')]
final class ReconcileApworldIncidentsCommand extends Command
{
    public function __construct(
        private readonly ReconcileApworldIncidents $reconcile,
        private readonly ApworldIncidentAlertDispatcher $alerts,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->reconcile->reconcile();

        if ($result->alreadyRunning) {
            $output->writeln('A reconciliation is already running: skipped.');

            return Command::SUCCESS;
        }

        if (!$result->runnerAvailable) {
            $output->writeln('Runner unavailable: nothing changed.');

            return Command::FAILURE;
        }

        $this->alerts->dispatchFor($result);

        $output->writeln(sprintf(
            'Apworld incidents: %d opened, %d resolved, %d ignored (admin override).',
            \count($result->openedIncidentIds),
            \count($result->resolvedIncidentIds),
            \count($result->ignoredIncidentIds),
        ));

        return Command::SUCCESS;
    }
}
