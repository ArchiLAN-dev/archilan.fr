<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\CommunityUserIdsQueryInterface;
use App\Community\Application\Message\RecomputeAllAchievementsMessage;
use App\Community\Application\RecomputeAchievements;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecomputeAllAchievementsHandler
{
    public function __construct(
        private RecomputeAchievements $recompute,
        private CommunityUserIdsQueryInterface $userIds,
    ) {
    }

    public function __invoke(RecomputeAllAchievementsMessage $message): void
    {
        foreach ($this->userIds->allUserIds() as $userId) {
            // Backstop catch-up: never notify (avoids spamming historical unlocks).
            $this->recompute->recomputeForUser($userId, notify: false);
        }
    }
}
