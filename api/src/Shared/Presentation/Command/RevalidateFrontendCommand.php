<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Command;

use App\Shared\Application\Port\PublicPageCacheInterface;
use App\Shared\Application\Port\PublicPageCacheOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Story 34.10: drop the cache of public pages by hand, after a fix made straight in the database or when a page
 * looks stale in prod. Calls the frontend right away, without the queue.
 */
#[AsCommand(
    name: 'app:frontend:revalidate',
    description: 'Revalidate frontend cache tags (events, event:<id>, posts, post:<slug>, partners).',
)]
final class RevalidateFrontendCommand extends Command
{
    private const string TAG_PATTERN = '/^[a-z]+(:[A-Za-z0-9_-]{1,120})?$/';

    public function __construct(private readonly PublicPageCacheInterface $cache)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('tags', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Tags to revalidate, e.g. events event:fef2ae3e08cf7b41b7819123bb7dbee9');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getArgument('tags');
        $tags = is_array($raw) ? array_values(array_filter($raw, is_string(...))) : [];
        $invalid = array_filter($tags, static fn (string $tag): bool => 1 !== preg_match(self::TAG_PATTERN, $tag));
        if ([] === $tags || [] !== $invalid) {
            $output->writeln(sprintf('<error>Invalid tag(s): %s</error>', implode(', ', $invalid)));

            return Command::INVALID;
        }

        $outcome = $this->cache->invalidate($tags);
        $output->writeln(match ($outcome) {
            PublicPageCacheOutcome::Revalidated => sprintf('Revalidated: %s', implode(', ', $tags)),
            PublicPageCacheOutcome::Disabled => 'Disabled: FRONTEND_INTERNAL_URL or REVALIDATE_SECRET is empty.',
            PublicPageCacheOutcome::Rejected => 'Rejected by the frontend: check REVALIDATE_SECRET on both sides.',
            PublicPageCacheOutcome::Unavailable => 'The frontend did not answer.',
        });

        return PublicPageCacheOutcome::Revalidated === $outcome ? Command::SUCCESS : Command::FAILURE;
    }
}
