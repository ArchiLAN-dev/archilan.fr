<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementDefinition;
use App\Community\Domain\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\AchievementGrant;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\Notification;

/**
 * Manual grant / revoke of an achievement by an admin (story 30.34). Sits beside the engine: it writes the
 * same AchievementGrant the rule engine writes, so the recipient sees the achievement unlocked like an
 * earned one, and it survives future recomputes (which are monotonic and skip already-granted keys).
 */
final readonly class AdminAchievementGrantService
{
    public function __construct(
        private AchievementDefinitionRepositoryInterface $definitions,
        private AchievementGrantRepositoryInterface $grants,
        private CommunityUserDirectoryQueryInterface $directory,
        private Notifier $notifier,
    ) {
    }

    /**
     * Award the achievement to the player identified by their community slug (the Community convention,
     * like comments/friendships). Returns 'ok' even when the player already holds it (idempotent).
     *
     * @return 'ok'|'definition_not_found'|'user_not_found'
     */
    public function grant(string $definitionId, string $slug): string
    {
        $definition = $this->definitions->findById($definitionId);
        if (!$definition instanceof AchievementDefinition) {
            return 'definition_not_found';
        }
        $userId = $this->directory->userIdForSlug($slug);
        if (null === $userId) {
            return 'user_not_found';
        }

        $key = $definition->getKey();
        // Idempotent: granting an already-held achievement is a no-op (no duplicate, no second notification).
        if (in_array($key, $this->grants->grantedKeys($userId), true)) {
            return 'ok';
        }

        $this->grants->save(AchievementGrant::grant($userId, $key, new \DateTimeImmutable()));
        $this->notifier->notify($userId, Notification::TYPE_ACHIEVEMENT_UNLOCKED, ['achievementKey' => $key]);

        return 'ok';
    }

    /**
     * @return 'ok'|'definition_not_found'
     */
    public function revoke(string $definitionId, string $slug): string
    {
        $definition = $this->definitions->findById($definitionId);
        if (!$definition instanceof AchievementDefinition) {
            return 'definition_not_found';
        }

        // Idempotent: an unknown slug or a non-existent grant is a no-op. A rule-based grant removed this
        // way may be re-granted on the next recompute (documented, not prevented).
        $userId = $this->directory->userIdForSlug($slug);
        if (null !== $userId) {
            $this->grants->deleteByUserAndKey($userId, $definition->getKey());
        }

        return 'ok';
    }
}
