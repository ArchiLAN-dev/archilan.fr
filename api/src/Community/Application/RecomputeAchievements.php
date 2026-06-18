<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\AchievementGrant;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\Notification;

/**
 * Deterministic achievement engine (epic §E.1, story 30.16): builds a user's MetricBag from the registered
 * providers, evaluates every active DB-configured definition's rule tree, and persists newly-unlocked
 * grants. Monotonic - it only ever adds grants, never revokes one (a later stat change, or a rule that
 * flips false, cannot un-earn an achievement). Idempotent.
 */
final readonly class RecomputeAchievements
{
    public function __construct(
        private AchievementDefinitionRepositoryInterface $definitions,
        private AchievementGrantRepositoryInterface $grants,
        private MetricBagBuilder $metrics,
        private Notifier $notifier,
    ) {
    }

    /**
     * @param bool $notify emit an in-app notification per newly-granted achievement (off for bulk backfill)
     *
     * @return int the number of newly granted achievements
     */
    public function recomputeForUser(string $userId, bool $notify = true): int
    {
        $bag = $this->metrics->build($userId);
        $alreadyGranted = array_flip($this->grants->grantedKeys($userId));
        $now = new \DateTimeImmutable();

        $added = 0;
        foreach ($this->definitions->allActive() as $definition) {
            if (isset($alreadyGranted[$definition->getKey()])) {
                continue;
            }
            if ($definition->matches($bag)) {
                $this->grants->save(AchievementGrant::grant($userId, $definition->getKey(), $now));
                if ($notify) {
                    $this->notifier->notify($userId, Notification::TYPE_ACHIEVEMENT_UNLOCKED, ['achievementKey' => $definition->getKey()]);
                }
                ++$added;
            }
        }

        return $added;
    }
}
