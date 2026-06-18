<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\CommunityProfile;
use App\Community\Domain\CommunityProfileRepositoryInterface;

/**
 * Refreshes cached avatar URLs off the request path (scheduled / on-demand). Resolution is the source
 * of truth; the page never resolves inline (story 30.2). Caches even a null result to throttle retries.
 */
final readonly class RefreshCommunityAvatars
{
    /** Avatars older than this are re-resolved. */
    public const TTL_SECONDS = 7 * 24 * 60 * 60;

    public function __construct(
        private CommunityProfileRepositoryInterface $profiles,
        private AvatarResolverInterface $resolver,
    ) {
    }

    /**
     * Refresh up to $limit stale/missing avatars. Returns how many were processed.
     */
    public function refreshStale(int $limit = 200): int
    {
        $now = new \DateTimeImmutable();
        $staleBefore = $now->modify(sprintf('-%d seconds', self::TTL_SECONDS));

        $profiles = $this->profiles->findNeedingAvatarRefresh($staleBefore, $limit);
        foreach ($profiles as $profile) {
            $profile->cacheAvatar($this->resolver->resolve($profile->getUserId()), $now);
        }
        $this->profiles->flush();

        return count($profiles);
    }

    /** Refresh a single member's avatar now (reused by profile edit in a later story). */
    public function refreshForUser(string $userId): void
    {
        $profile = $this->profiles->findByUserId($userId);
        if (!$profile instanceof CommunityProfile) {
            return;
        }

        $profile->cacheAvatar($this->resolver->resolve($userId), new \DateTimeImmutable());
        $this->profiles->flush();
    }
}
