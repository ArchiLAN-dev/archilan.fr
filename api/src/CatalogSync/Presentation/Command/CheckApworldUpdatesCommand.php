<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation\Command;

use App\CatalogSync\Application\Command\CheckApworldUpdatesService;
use App\CatalogSync\Application\Command\SubmitAvailableApworldUpdates;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:check-apworld-updates', description: 'Check GitHub for APWorld version updates; --submit also queues them for the automatic update, like the nightly pass.')]
final class CheckApworldUpdatesCommand extends Command
{
    /**
     * Same bound as the rolling catalogue test. Each candidate is a test generation on the orchestrator,
     * two at a time by default, and a candidate still without a verdict after 30 minutes expires: past a
     * few dozen per run, the last ones would expire and wait for a later pass.
     */
    public const int MAX_LIMIT = 200;

    public function __construct(
        private readonly CheckApworldUpdatesService $service,
        private readonly SubmitAvailableApworldUpdates $submitUpdates,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('submit', null, InputOption::VALUE_NONE, 'Queue the updates found for the automatic update (test, then promotion), as the nightly pass does.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, sprintf('With --submit: how many to queue in this run, instead of APWORLD_AUTO_UPDATE_BATCH_SIZE (1 to %d).', self::MAX_LIMIT));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $submit = true === $input->getOption('submit');
        $rawLimit = $input->getOption('limit');
        $limit = null;
        if (null !== $rawLimit) {
            if (!$submit) {
                $output->writeln('<error>--limit only applies with --submit.</error>');

                return Command::INVALID;
            }
            $limit = is_string($rawLimit) && ctype_digit($rawLimit) ? (int) $rawLimit : 0;
            if ($limit < 1 || $limit > self::MAX_LIMIT) {
                $output->writeln(sprintf('<error>--limit must be a whole number between 1 and %d.</error>', self::MAX_LIMIT));

                return Command::INVALID;
            }
        }

        $result = $this->service->checkAll();

        $output->writeln(sprintf('Checked %d APWorld(s).', $result->checked));

        if ($result->failed > 0) {
            $output->writeln(sprintf('%d check(s) failed (network or unreadable answer) and were skipped, see the logs.', $result->failed));
        }

        if ($result->rateLimitHit) {
            $output->writeln('GitHub rate limit reached, batch stopped early.');
        }

        $output->writeln(sprintf('%d update(s) available:', \count($result->updatesAvailable)));
        foreach ($result->updatesAvailable as $update) {
            $output->writeln(sprintf('  - %s -> %s', $update->gameName, $update->latestTag));
        }

        if (!$submit) {
            if ([] !== $result->updatesAvailable) {
                $output->writeln('Nothing was applied: run again with --submit to queue them for the automatic update now.');
            }

            return Command::SUCCESS;
        }

        if ([] === $result->updatesAvailable) {
            return Command::SUCCESS;
        }

        $report = $this->submitUpdates->submit($result->updatesAvailable, $limit);
        $output->writeln(sprintf(
            'Automatic update: %d queued, %d deferred to a later pass, %d skipped, %d failed, %d ambiguous release(s).',
            $report->queued,
            $report->deferred,
            $report->skipped,
            $report->failed,
            \count($report->openedIncidentIds),
        ));
        $output->writeln('Each queued update is tested, then promoted or rejected by the reconciliation that runs every 5 minutes (or now: app:apworlds:incidents-reconcile). Follow them in "Santé des apworlds".');

        return Command::SUCCESS;
    }
}
