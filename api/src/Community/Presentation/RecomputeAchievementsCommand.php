<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\CommunityUserIdsQueryInterface;
use App\Community\Application\RecomputeAchievements;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('userId', InputArgument::OPTIONAL, 'Recompute a single user; omit to recompute all.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userArgument = $input->getArgument('userId');
        $userIds = is_string($userArgument) && '' !== $userArgument
            ? [$userArgument]
            : $this->userIds->allUserIds();

        $granted = 0;
        foreach ($userIds as $userId) {
            // Bulk/backfill recompute must not spam every historical unlock as a notification.
            $granted += $this->recompute->recomputeForUser($userId, notify: false);
        }

        $output->writeln(sprintf('Recomputed %d user(s), %d new grant(s).', count($userIds), $granted));

        return Command::SUCCESS;
    }
}
