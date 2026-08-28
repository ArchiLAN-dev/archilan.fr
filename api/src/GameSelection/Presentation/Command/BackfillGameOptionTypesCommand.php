<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Command;

use App\GameSelection\Application\Command\BackfillGameOptionTypes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:games:backfill-option-types', description: 'Re-read introspected option types for existing apworld games; --reintrospect regenerates them first (stories 9.25 / 9.53).')]
final class BackfillGameOptionTypesCommand extends Command
{
    public function __construct(
        private readonly BackfillGameOptionTypes $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'reintrospect',
                null,
                InputOption::VALUE_NONE,
                'Ask the runner to re-run introspection before reading it. Needed after the introspection itself changes: '
                .'without it, the sidecar written at upload is re-read unchanged. Runs one container per apworld, so it is slow.',
            )
            ->addOption(
                'game',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit the sweep to a single game, by slug.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $reintrospect = true === $input->getOption('reintrospect');
        $slug = $input->getOption('game');
        $slug = is_string($slug) && '' !== $slug ? $slug : null;

        $result = $this->service->run($reintrospect, $slug);

        $output->writeln(sprintf(
            'Option types backfill: %d apworld game(s) processed, %d updated.',
            $result->processed,
            $result->updated,
        ));

        if ($reintrospect) {
            $output->writeln(sprintf(
                'Re-introspection: %d succeeded, %d failed (their previous introspection is untouched).',
                $result->reintrospected,
                $result->reintrospectionFailed,
            ));
        }

        // A run where every single apworld refused introspection is a failure worth an exit code:
        // it is what a broken runner or a bad image looks like. One failure among many is not - the
        // sweep did its job for the rest, and the count above says so.
        if ($reintrospect && $result->processed > 0 && 0 === $result->reintrospected) {
            $output->writeln('<error>No apworld could be introspected.</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
