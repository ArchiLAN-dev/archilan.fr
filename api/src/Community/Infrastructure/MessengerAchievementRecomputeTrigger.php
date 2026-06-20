<?php

declare(strict_types=1);

namespace App\Community\Infrastructure;

use App\Community\Application\Message\RecomputeAchievementsForUserMessage;
use App\Sessions\Application\AchievementRecomputeTriggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Community-side adapter for the Sessions {@see AchievementRecomputeTriggerInterface} (story 30.26):
 * dispatches one async recompute message per player so the work happens off the archival request path.
 */
final readonly class MessengerAchievementRecomputeTrigger implements AchievementRecomputeTriggerInterface
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function recomputeForUsers(array $userIds): void
    {
        foreach (array_unique($userIds) as $userId) {
            if ('' === $userId) {
                continue;
            }
            $this->messageBus->dispatch(new RecomputeAchievementsForUserMessage($userId));
        }
    }
}
