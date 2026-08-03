<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Command;

use App\Sessions\Application\Handler\BuildSessionRecapJobHandler;
use App\Sessions\Application\Message\BuildSessionRecapJob;
use App\Sessions\Domain\Repository\SessionRecapRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds the recap projection of finished sessions from their live feed.
 *
 * A recap is normally built once, when the run is archived. It is a projection, so it never
 * self-heals: a session whose recap was written by a faulty builder keeps the faulty result
 * forever. This command is the repair route - it re-runs the build synchronously (no messenger
 * worker needed) and reports what came out, so an operator can see the graph is no longer empty.
 */
#[AsCommand(name: 'app:sessions:rebuild-recap', description: 'Rebuild the recap projection of finished sessions from their live feed.')]
final class RebuildSessionRecapCommand extends Command
{
    public function __construct(
        private readonly BuildSessionRecapJobHandler $buildRecap,
        private readonly SessionRecapRepositoryInterface $recaps,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'sessionId',
            InputArgument::IS_ARRAY | InputArgument::REQUIRED,
            'One or more finished session ids to rebuild.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getArgument('sessionId');
        $sessionIds = is_array($raw) ? array_values(array_filter($raw, is_string(...))) : [];

        $failed = 0;
        foreach ($sessionIds as $sessionId) {
            ($this->buildRecap)(new BuildSessionRecapJob($sessionId));

            $recap = $this->recaps->findBySessionId($sessionId);
            if (null === $recap) {
                // The handler declines silently on an unknown or unfinished session.
                $output->writeln(sprintf('<comment>%s: no recap (unknown session, or not finished).</comment>', $sessionId));
                ++$failed;

                continue;
            }

            $output->writeln(sprintf(
                '%s: %d node(s), %d edge(s), %d local item entr(ies), %d superlative(s).',
                $sessionId,
                count($recap->getNodes()),
                count($recap->getEdges()),
                count($recap->getLocalItems()),
                count($recap->getSuperlatives()),
            ));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
