<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Command;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Completes runs whose session is finished but which were left in a non-terminal state (story 17.25).
 *
 * Repairs the damage done while `Run::markStopped()` had no terminal guard: the `session.stopped`
 * webhook fired ~20 s after the owner finished a run and demoted it back to idle, which hid its
 * recap - the recap card only renders on a completed run - and left the run restartable. The guard
 * stops it happening again; nothing repairs the rows already written, hence this command.
 */
#[AsCommand(name: 'app:runs:repair-finished', description: 'Complete runs whose session is finished but which stayed idle or transitional.')]
final class RepairFinishedRunsCommand extends Command
{
    /** Statuses a run can be stuck in while its session is already over. */
    private const array REPAIRABLE = [
        Run::STATUS_IDLE,
        Run::STATUS_ACTIVE,
        Run::STATUS_STOPPING,
        Run::STATUS_RESTARTING,
    ];

    public function __construct(
        private readonly RunRepositoryInterface $runs,
        private readonly SessionRepositoryInterface $sessions,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = true === $input->getOption('dry-run');
        $repaired = 0;

        foreach ($this->runs->findByStatuses(self::REPAIRABLE) as $run) {
            $sessionId = $run->getSessionId();
            if (null === $sessionId) {
                continue;
            }

            $session = $this->sessions->findById($sessionId);
            if (!$session instanceof Session || Session::STATUS_FINISHED !== $session->getStatus()) {
                continue;
            }

            $output->writeln(sprintf(
                '%s "%s" : %s -> completed',
                $run->getId(),
                $run->getTitle(),
                $run->getStatus(),
            ));

            if (!$dryRun) {
                $run->markSessionFinished($this->clock->now());
            }
            ++$repaired;
        }

        if (!$dryRun && $repaired > 0) {
            $this->runs->flush();
        }

        $output->writeln(sprintf(
            '%d run(s) %s.',
            $repaired,
            $dryRun ? 'à réparer (dry-run, rien écrit)' : 'réparée(s)',
        ));

        return Command::SUCCESS;
    }
}
