<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\AchievementGrantRepositoryInterface;
use App\Community\Domain\Audience;
use App\Community\Domain\BannerPreset;
use App\Community\Domain\CommunityProfile;
use App\Community\Domain\CommunityProfileRepositoryInterface;
use App\Community\Domain\CommunityXp;
use App\Community\Domain\Kudos;
use App\Community\Domain\KudosRepositoryInterface;
use App\Community\Domain\Level;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Read facade for the community profile (stories 30.1-30.6). Composes the enriched read model and gates
 * the customization surface via the shared ProfileVisibility (audience vs viewer tier, block overrides).
 * Identity + aggregate stats + achievements + level stay public. The profile row is created lazily only
 * when the owner views their own profile (or edits it) - never on an anonymous/foreign read.
 */
final readonly class CommunityProfileView
{
    public function __construct(
        private CommunityProfileQueryInterface $query,
        private CommunityProfileRepositoryInterface $profiles,
        private GameRepositoryInterface $games,
        private AchievementGrantRepositoryInterface $achievementGrants,
        private AchievementDefinitionRepositoryInterface $achievementDefinitions,
        private ProfileVisibility $visibility,
        private KudosRepositoryInterface $kudos,
        private CommunityPresenceQueryInterface $presence,
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
     *     achievements: list<array{key: string, name: string, description: string, unlocked: bool, unlockedAt: string|null, grantId: string|null, kudosCount: int}>,
     *     presence: array{playing: bool, sessionId: string|null, game: string|null},
     *     customization: array{bio: string|null, tagline: string|null, pronouns: string|null, bannerPreset: string, socialLinks: list<array{label: string, url: string}>, favoriteGames: list<array{id: string, name: string, slug: string, coverImageUrl: string|null}>, showcaseLayout: list<string>}|null
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
        // when the owner views their own profile (story 30.11).
        $achievements = $this->achievementsFor($model['userId'], $viewerId !== $model['userId']);
        $unlockedCount = count(array_filter($achievements, static fn (array $a): bool => true === $a['unlocked']));
        $xp = CommunityXp::compute(
            $model['stats']['goalCompletions'],
            $model['stats']['totalChecksDone'],
            $model['stats']['runsParticipated'],
            $unlockedCount,
        );
        $level = Level::fromXp($xp);

        $live = $this->presence->playing([$model['userId']])[$model['userId']] ?? null;
        $presence = [
            'playing' => null !== $live,
            'sessionId' => $live['sessionId'] ?? null,
            'game' => $live['game'] ?? null,
        ];

        $customization = null;
        if (null !== $profile && $this->visibility->canSee($viewerId, $model['userId'])) {
            $customization = [
                'bio' => $profile->getBio(),
                'tagline' => $profile->getTagline(),
                'pronouns' => $profile->getPronouns(),
                'bannerPreset' => $profile->getBannerPreset(),
                'socialLinks' => $profile->getSocialLinks(),
                'favoriteGames' => $this->resolveFavoriteGames($profile->getFavoriteGameIds()),
                'showcaseLayout' => $profile->getShowcaseLayout(),
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
            'presence' => $presence,
            'customization' => $customization,
        ];
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
     * @return array{bio: string|null, tagline: string|null, pronouns: string|null, bannerPreset: string, socialLinks: list<array{label: string, url: string}>, favoriteGames: list<array{id: string, name: string, slug: string, coverImageUrl: string|null}>, audience: string, showcaseLayout: list<string>}
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
            'showcaseLayout' => $profile?->getShowcaseLayout() ?? [],
        ];
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
