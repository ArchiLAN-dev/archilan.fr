<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\Audience;
use App\Community\Domain\BannerPreset;
use App\Community\Domain\CommunityProfile;
use App\Community\Domain\CommunityProfileRepositoryInterface;
use App\Community\Domain\Kudos;
use App\Community\Domain\KudosRepositoryInterface;
use App\Community\Domain\ShowcaseWidget;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Membership\Application\ActiveMembershipQueryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Read facade for the community profile (stories 30.1-30.6). Composes the enriched read model and gates
 * the customization surface via the shared ProfileVisibility (audience vs viewer tier, block overrides).
 * Identity + aggregate stats + achievements + level stay public. The profile row is created lazily only
 * when the owner views their own profile (or edits it) - never on an anonymous/foreign read.
 */
final readonly class CommunityProfileView
{
    /** Recent unlocked achievements surfaced on the profile card; the rest live on the catalogue page. */
    private const PROFILE_RECENT_LIMIT = 6;

    public function __construct(
        private CommunityProfileQueryInterface $query,
        private CommunityProfileRepositoryInterface $profiles,
        private GameRepositoryInterface $games,
        private AchievementGrantRepositoryInterface $achievementGrants,
        private AchievementDefinitionRepositoryInterface $achievementDefinitions,
        private ProfileVisibility $visibility,
        private KudosRepositoryInterface $kudos,
        private CommunityPresenceQueryInterface $presence,
        private ActiveMembershipQueryInterface $memberships,
        private AvatarUrlResolver $avatarUrls,
        private CommunityLevelQuery $levels,
        private AchievementRarityQueryInterface $rarity,
    ) {
    }

    /**
     * @return array{
     *     slug: string,
     *     displayName: string|null,
     *     joinedAt: string,
     *     avatarUrl: string|null,
     *     audience: string,
     *     badges: array{member: bool, admin: bool},
     *     stats: array{runsParticipated: int, goalCompletions: int, goalCompletionRate: float, totalChecksDone: int, totalItemsReceived: int},
     *     level: array{level: int, xp: int, xpIntoLevel: int, xpForNextLevel: int},
     *     achievements: list<array{key: string, name: string, description: string, unlocked: bool, unlockedAt: string|null, grantId: string|null, kudosCount: int}>,
     *     achievementStats: array{unlocked: int, total: int},
     *     presence: array{playing: bool, sessionId: string|null, game: string|null},
     *     customization: array{bio: string|null, tagline: string|null, pronouns: string|null, bannerPreset: string, avatarFrame: string|null, socialLinks: list<array{label: string, url: string}>, favoriteGames: list<array{id: string, name: string, slug: string, coverImageUrl: string|null}>, showcaseLayout: list<string>}|null
     * }|null
     */
    public function forSlug(string $slug, ?string $viewerId): ?array
    {
        $model = $this->query->forSlug($slug);
        if (null === $model) {
            return null;
        }

        // Create the row lazily only when the owner views their own profile; a foreign/anonymous read
        // must never write.
        $profile = $viewerId === $model['userId']
            ? $this->ensureProfile($model['userId'])
            : $this->profiles->findByUserId($model['userId']);

        $audience = $profile?->getAudience() ?? Audience::MEMBERS;

        // Kudos are peer-only: a viewer can't kudos their own achievements, so the target is suppressed
        // when the owner views their own profile (story 30.11). The profile card shows only the most
        // recent unlocks + counts; the full catalogue lives on its own page (story 30.31).
        $achievements = $this->achievementsFor($model['userId'], $viewerId !== $model['userId']);
        $unlocked = array_values(array_filter($achievements, static fn (array $a): bool => true === $a['unlocked']));

        // Level/XP from the shared query so every surface (profile, run participant detail…) agrees.
        $level = $this->levels->levelFor($model['userId']);

        $live = $this->presence->playing([$model['userId']])[$model['userId']] ?? null;
        $presence = [
            'playing' => null !== $live,
            'sessionId' => $live['sessionId'] ?? null,
            'game' => $live['game'] ?? null,
        ];

        // Public recognition badges (always visible, never audience-gated). Member status is a *live*
        // membership lookup, never the stale-prone ROLE_MEMBER (AC-M2); admin is a stable role.
        $badges = [
            'member' => $this->memberships->hasActiveMembership($model['userId']),
            'admin' => $model['isAdmin'],
        ];

        $customization = null;
        if (null !== $profile && $this->visibility->canSee($viewerId, $model['userId'])) {
            $customization = [
                'bio' => $profile->getBio(),
                'tagline' => $profile->getTagline(),
                'pronouns' => $profile->getPronouns(),
                'bannerPreset' => $profile->getBannerPreset(),
                'avatarFrame' => $profile->getAvatarFrame(),
                'socialLinks' => $profile->getSocialLinks(),
                'favoriteGames' => $this->resolveFavoriteGames($profile->getFavoriteGameIds()),
                'showcaseLayout' => $this->validShowcase($profile->getShowcaseLayout()),
            ];
        }

        return [
            'slug' => $model['slug'],
            // The owner's display-name override wins over the account name; falls back when unset.
            'displayName' => $profile?->getDisplayName() ?? $model['displayName'],
            'joinedAt' => $model['joinedAt'],
            'avatarUrl' => $this->avatarUrls->resolve($profile?->getCustomAvatarKey(), $profile?->getAvatarUrl()),
            'audience' => $audience,
            'badges' => $badges,
            'stats' => $model['stats'],
            'level' => [
                'level' => $level['level'],
                'xp' => $level['xp'],
                'xpIntoLevel' => $level['xpIntoLevel'],
                'xpForNextLevel' => $level['xpForNextLevel'],
            ],
            'achievements' => $this->recentUnlocked($unlocked),
            'achievementStats' => ['unlocked' => count($unlocked), 'total' => count($achievements)],
            'presence' => $presence,
            'customization' => $customization,
        ];
    }

    /**
     * Full achievements catalogue with this player's unlocked/locked state + rarity, for the dedicated
     * « Tous les succès » page. Same kudos gating as the profile card. Null when the slug has no live user.
     *
     * @return array{
     *     slug: string,
     *     displayName: string|null,
     *     avatarUrl: string|null,
     *     achievements: list<array{key: string, name: string, description: string, unlocked: bool, unlockedAt: string|null, grantId: string|null, kudosCount: int, rarity: array{count: int, percent: int|null}}>
     * }|null
     */
    public function achievementsCatalogFor(string $slug, ?string $viewerId): ?array
    {
        $model = $this->query->forSlug($slug);
        if (null === $model) {
            return null;
        }

        $profile = $this->profiles->findByUserId($model['userId']);
        $achievements = $this->achievementsFor($model['userId'], $viewerId !== $model['userId']);

        $snapshot = $this->rarity->snapshot();
        $memberCount = $snapshot['memberCount'];

        $withRarity = array_map(static function (array $a) use ($snapshot, $memberCount): array {
            $count = $snapshot['grantsByKey'][$a['key']] ?? 0;

            return [
                ...$a,
                'rarity' => [
                    'count' => $count,
                    'percent' => $memberCount > 0 ? (int) round($count / $memberCount * 100) : null,
                ],
            ];
        }, $achievements);

        return [
            'slug' => $model['slug'],
            'displayName' => $profile?->getDisplayName() ?? $model['displayName'],
            'avatarUrl' => $this->avatarUrls->resolve($profile?->getCustomAvatarKey(), $profile?->getAvatarUrl()),
            'achievements' => $withRarity,
        ];
    }

    /**
     * The most recently unlocked achievements (by unlock date desc), capped for the profile card.
     *
     * @param list<array{key: string, name: string, description: string, unlocked: bool, unlockedAt: string|null, grantId: string|null, kudosCount: int}> $unlocked
     *
     * @return list<array{key: string, name: string, description: string, unlocked: bool, unlockedAt: string|null, grantId: string|null, kudosCount: int}>
     */
    private function recentUnlocked(array $unlocked): array
    {
        usort($unlocked, static fn (array $a, array $b): int => ($b['unlockedAt'] ?? '') <=> ($a['unlockedAt'] ?? ''));

        return array_slice($unlocked, 0, self::PROFILE_RECENT_LIMIT);
    }

    /**
     * @param bool $kudosable whether the viewer may kudos these achievements (false for the owner's own view)
     *
     * @return list<array{key: string, name: string, description: string, unlocked: bool, unlockedAt: string|null, grantId: string|null, kudosCount: int}>
     */
    private function achievementsFor(string $userId, bool $kudosable): array
    {
        $grantByKey = [];
        foreach ($this->achievementGrants->findByUser($userId) as $grant) {
            $grantByKey[$grant->getAchievementKey()] = $grant;
        }
        $kudosCounts = $kudosable ? $this->kudos->countsFor(
            Kudos::TARGET_ACHIEVEMENT,
            array_values(array_map(static fn ($g): string => $g->getId(), $grantByKey)),
        ) : [];

        $out = [];
        foreach ($this->achievementDefinitions->all() as $definition) {
            $grant = $grantByKey[$definition->getKey()] ?? null;
            // A deactivated definition stays visible only for users who already earned it (monotonic);
            // otherwise it drops off the public catalogue.
            if (!$definition->isActive() && null === $grant) {
                continue;
            }
            // grantId is the kudos target; null it for the owner's own view so no button renders.
            $grantId = $kudosable ? $grant?->getId() : null;

            $out[] = [
                'key' => $definition->getKey(),
                'name' => $definition->getName(),
                'description' => $definition->getDescription(),
                'unlocked' => null !== $grant,
                'unlockedAt' => $grant?->getUnlockedAt()->format(\DateTimeInterface::ATOM),
                'grantId' => $grantId,
                'kudosCount' => null !== $grantId ? ($kudosCounts[$grantId] ?? 0) : 0,
            ];
        }

        return $out;
    }

    /**
     * Raw, always-full customization for the owner's edit form (self only).
     *
     * @return array{displayName: string|null, bio: string|null, tagline: string|null, pronouns: string|null, bannerPreset: string, avatarFrame: string|null, avatarUrl: string|null, hasCustomAvatar: bool, socialLinks: list<array{label: string, url: string}>, favoriteGames: list<array{id: string, name: string, slug: string, coverImageUrl: string|null}>, audience: string, showcaseLayout: list<string>}
     */
    public function editableForUser(string $userId): array
    {
        $profile = $this->ensureProfile($userId);

        return [
            'displayName' => $profile?->getDisplayName(),
            'bio' => $profile?->getBio(),
            'tagline' => $profile?->getTagline(),
            'pronouns' => $profile?->getPronouns(),
            'bannerPreset' => $profile?->getBannerPreset() ?? BannerPreset::DEFAULT,
            'avatarFrame' => $profile?->getAvatarFrame(),
            'avatarUrl' => $this->avatarUrls->resolve($profile?->getCustomAvatarKey(), $profile?->getAvatarUrl()),
            'hasCustomAvatar' => null !== $profile?->getCustomAvatarKey(),
            'socialLinks' => $profile?->getSocialLinks() ?? [],
            'favoriteGames' => $this->resolveFavoriteGames($profile?->getFavoriteGameIds() ?? []),
            'audience' => $profile?->getAudience() ?? Audience::MEMBERS,
            'showcaseLayout' => $this->validShowcase($profile?->getShowcaseLayout() ?? []),
        ];
    }

    /**
     * Drop retired/unknown showcase widget keys on read, so a layout saved before a widget was retired
     * (e.g. featured_achievements) never surfaces a raw key to the frontend.
     *
     * @param list<string> $layout
     *
     * @return list<string>
     */
    private function validShowcase(array $layout): array
    {
        return array_values(array_filter($layout, static fn (string $w): bool => ShowcaseWidget::isValid($w)));
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
