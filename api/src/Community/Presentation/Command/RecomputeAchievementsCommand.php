<?php

declare(strict_types=1);

namespace App\Community\Presentation\Command;

use App\Community\Application\Command\ActivateAchievements;
use App\Community\Application\Command\RecomputeAchievements;
use App\Community\Application\Query\CommunityUserIdsQueryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'community:achievements:recompute',
    description: 'Recompute achievement grants from existing stats (one user, or all). Monotonic.',
)]
final class RecomputeAchievementsCommand extends Command
{
    public function __construct(
        private readonly RecomputeAchievements $recompute,
        private readonly CommunityUserIdsQueryInterface $userIds,
        private readonly ActivateAchievements $activate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('userId', InputArgument::OPTIONAL, 'Recompute a single user; omit to recompute all.');
        // Story 43.16: run once right after seeding new achievements, so the members hear of what they already earned.
        $this->addOption('notify', null, InputOption::VALUE_NONE, 'Notify each new grant (achievement_unlocked).');
        // Story 43.18: achievements seeded inactive are turned on here, just before the grants they bring are notified.
        $this->addOption('activate', null, InputOption::VALUE_REQUIRED, 'Comma-separated achievement keys to turn on first.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userArgument = $input->getArgument('userId');
        $userIds = is_string($userArgument) && '' !== $userArgument
            ? [$userArgument]
            : $this->userIds->allUserIds();

        $keys = $input->getOption('activate');
        if (is_string($keys) && '' !== trim($keys)) {
            $activated = $this->activate->activate(array_values(array_filter(array_map(trim(...), explode(',', $keys)), static fn (string $key): bool => '' !== $key)));
            $output->writeln(sprintf('Activated %d achievement(s).', $activated));
        }

        // Bulk/backfill recompute must not spam every historical unlock as a notification, unless asked to.
        $notify = true === $input->getOption('notify');
        $granted = 0;
        foreach ($userIds as $userId) {
            $granted += $this->recompute->recomputeForUser($userId, notify: $notify);
        }

        $output->writeln(sprintf('Recomputed %d user(s), %d new grant(s).', count($userIds), $granted));

        return Command::SUCCESS;
    }
}
