<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Message\RecomputeAchievementsForUserMessage;
use App\Community\Application\RecomputeAchievements;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecomputeAchievementsForUserHandler
{
    public function __construct(private RecomputeAchievements $recompute)
    {
    }

    public function __invoke(RecomputeAchievementsForUserMessage $message): void
    {
        $this->recompute->recomputeForUser($message->userId, notify: true);
    }
}
