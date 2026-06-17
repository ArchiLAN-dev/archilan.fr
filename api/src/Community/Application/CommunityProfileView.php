<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementCatalog;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\Audience;
use App\Community\Domain\AudiencePolicy;
use App\Community\Domain\BannerPreset;
use App\Community\Domain\CommunityProfile;
use App\Community\Domain\CommunityProfileRepositoryInterface;
use App\Community\Domain\CommunityXp;
use App\Community\Domain\Level;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Membership\Application\ActiveMembershipQueryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Read facade for the community profile (stories 30.1-30.3). Composes the enriched read model, lazily
 * ensures the profile row, and gates the customization surface by audience vs the viewer's tier
 * (server-side, every read). Identity + aggregate stats stay public regardless.
 */
final readonly class CommunityProfileView
{
    public function __construct(
        private CommunityProfileQueryInterface $query,
        private CommunityProfileRepositoryInterface $profiles,
        private ActiveMembershipQueryInterface $memberships,
        private GameRepositoryInterface $games,
        private AchievementGrantRepositoryInterface $achievementGrants,
    ) {
    }

    /**
     * @return array{
     *     slug: string,
     *     displayName: string|null,
     *     joinedAt: string,
     *     avatarUrl: string|null,
     *     audience: string,
     *     stats: array{runsParticipated: int, goalCompletions: int, goalCompletionRate: float, totalChecksDone: int, totalItemsReceived: int},
     *     level: array{level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int},
     *     achievements: list<array{key: string, name: string, description: string, unlocked: bool, unlockedAt: string|null}>,
     *     customization: array{bio: string|null, tagline: string|null, pronouns: string|null, bannerPreset: string, socialLinks: list<array{label: string, url: string}>, favoriteGames: list<array{id: string, name: string, slug: string, coverImageUrl: string|null}>}|null
     * }|null
     */
    public function forSlug(string $slug, ?string $viewerId): ?array
    {
        $model = $this->query->forSlug($slug);
        if (null === $model) {
            return null;
        }

        $profile = $this->ensureProfile($model['userId']);
        $audience = $profile?->getAudience() ?? Audience::MEMBERS;
        $tier = $this->viewerTier($viewerId, $model['userId']);

        $achievements = $this->achievementsFor($model['userId']);
        $unlockedCount = count(array_filter($achievements, static fn (array $a): bool => true === $a['unlocked']));
        $xp = CommunityXp::compute(
            $model['stats']['goalCompletions'],
            $model['stats']['totalChecksDone'],
            $model['stats']['runsParticipated'],
            $unlockedCount,
        );
        $level = Level::fromXp($xp);

        $customization = null;
        if (null !== $profile && AudiencePolicy::canView($tier, $audience)) {
            $customization = [
                'bio' => $profile->getBio(),
                'tagline' => $profile->getTagline(),
                'pronouns' => $profile->getPronouns(),
                'bannerPreset' => $profile->getBannerPreset(),
                'socialLinks' => $profile->getSocialLinks(),
                'favoriteGames' => $this->resolveFavoriteGames($profile->getFavoriteGameIds()),
            ];
        }

        return [
            'slug' => $model['slug'],
            'displayName' => $model['displayName'],
            'joinedAt' => $model['joinedAt'],
            'avatarUrl' => $profile?->getAvatarUrl(),
            'audience' => $audience,
            'stats' => $model['stats'],
            'level' => [
                'level' => $level->level,
                'xp' => $xp,
                'xpIntoLevel' => $level->xpIntoLevel,
                'xpForNextLevel' => $level->xpForNextLevel,
            ],
            'achievements' => $achievements,
            'customization' => $customization,
        ];
    }

    /**
     * @return list<array{key: string, name: string, description: string, unlocked: bool, unlockedAt: string|null}>
     */
    private function achievementsFor(string $userId): array
    {
        $unlockedAt = [];
        foreach ($this->achievementGrants->findByUser($userId) as $grant) {
            $unlockedAt[$grant->getAchievementKey()] = $grant->getUnlockedAt()->format(\DateTimeInterface::ATOM);
        }

        return array_map(
            static fn ($definition): array => [
                'key' => $definition->key,
                'name' => $definition->name,
                'description' => $definition->description,
                'unlocked' => isset($unlockedAt[$definition->key]),
                'unlockedAt' => $unlockedAt[$definition->key] ?? null,
            ],
            AchievementCatalog::all(),
        );
    }

    /**
     * Raw, always-full customization for the owner's edit form (self only).
     *
     * @return array{bio: string|null, tagline: string|null, pronouns: string|null, bannerPreset: string, socialLinks: list<array{label: string, url: string}>, favoriteGames: list<array{id: string, name: string, slug: string, coverImageUrl: string|null}>, audience: string}
     */
    public function editableForUser(string $userId): array
    {
        $profile = $this->ensureProfile($userId);

        return [
            'bio' => $profile?->getBio(),
            'tagline' => $profile?->getTagline(),
            'pronouns' => $profile?->getPronouns(),
            'bannerPreset' => $profile?->getBannerPreset() ?? BannerPreset::DEFAULT,
            'socialLinks' => $profile?->getSocialLinks() ?? [],
            'favoriteGames' => $this->resolveFavoriteGames($profile?->getFavoriteGameIds() ?? []),
            'audience' => $profile?->getAudience() ?? Audience::MEMBERS,
        ];
    }

    private function viewerTier(?string $viewerId, string $ownerId): string
    {
        if (null === $viewerId) {
            return AudiencePolicy::TIER_ANONYMOUS;
        }
        if ($viewerId === $ownerId) {
            return AudiencePolicy::TIER_SELF;
        }
        if ($this->memberships->hasActiveMembership($viewerId)) {
            return AudiencePolicy::TIER_MEMBER;
        }

        return AudiencePolicy::TIER_AUTHENTICATED;
    }

    /**
     * @param list<string> $gameIds
     *
     * @return list<array{id: string, name: string, slug: string, coverImageUrl: string|null}>
     */
    private function resolveFavoriteGames(array $gameIds): array
    {
        if ([] === $gameIds) {
            return [];
        }

        $byId = [];
        foreach ($this->games->findByIds($gameIds) as $game) {
            $byId[$game->getId()] = $game;
        }

        $result = [];
        foreach ($gameIds as $id) {
            $game = $byId[$id] ?? null;
            if ($game instanceof Game) {
                $result[] = [
                    'id' => $game->getId(),
                    'name' => $game->getName(),
                    'slug' => $game->getSlug(),
                    'coverImageUrl' => $game->getCoverImageUrl(),
                ];
            }
        }

        return $result;
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
            return $this->profiles->findByUserId($userId);
        }
    }
}
