<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\CommunityProfile;
use App\Community\Domain\CommunityProfileRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Read facade for the public community profile page (story 30.1). Composes the enriched read model and
 * lazily ensures the member has a `CommunityProfile` row (idempotent upsert on the unique userId, so
 * later stories - customization, avatar cache, showcases - always have a row to attach to).
 */
final readonly class CommunityProfileView
{
    public function __construct(
        private CommunityProfileQueryInterface $query,
        private CommunityProfileRepositoryInterface $profiles,
    ) {
    }

    /**
     * @return array{
     *     slug: string,
     *     displayName: string|null,
     *     joinedAt: string,
     *     avatarUrl: string|null,
     *     stats: array{
     *         runsParticipated: int,
     *         goalCompletions: int,
     *         goalCompletionRate: float,
     *         totalChecksDone: int,
     *         totalItemsReceived: int
     *     }
     * }|null
     */
    public function forSlug(string $slug): ?array
    {
        $model = $this->query->forSlug($slug);
        if (null === $model) {
            return null;
        }

        $profile = $this->ensureProfile($model['userId']);

        return [
            'slug' => $model['slug'],
            'displayName' => $model['displayName'],
            'joinedAt' => $model['joinedAt'],
            // Cached snapshot (story 30.2); refreshed off the request path by community:avatars:refresh.
            'avatarUrl' => $profile?->getAvatarUrl(),
            'stats' => $model['stats'],
        ];
    }

    private function ensureProfile(string $userId): ?CommunityProfile
    {
        $existing = $this->profiles->findByUserId($userId);
        if (null !== $existing) {
            return $existing;
        }

        $profile = CommunityProfile::create($userId, new \DateTimeImmutable());
        try {
            $this->profiles->save($profile);

            return $profile;
        } catch (UniqueConstraintViolationException) {
            // A concurrent first view won the insert race - reload the row that now exists.
            return $this->profiles->findByUserId($userId);
        }
    }
}
