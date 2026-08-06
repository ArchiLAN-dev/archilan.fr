<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Application\Support\AchievementImageUrlResolver;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Sessions\Application\Query\ViewableSessionsQuery;

/**
 * Everything the /communaute hub needs that no other endpoint already serves (story 30.38): how many
 * members there are, who is playing right now, and which achievements just fell.
 *
 * Grouped into one query - and one endpoint - because the three are rendered together above the fold;
 * three round-trips for one screen is the cost this class exists to avoid.
 *
 * **Who appears** is the listable-member rule (a public slug, not deleted), applied in SQL by the two
 * underlying queries - the same population the directory lists and the rarity percentages divide by.
 * ProfileVisibility is deliberately NOT applied: it gates the *customization* block of a profile (bio,
 * banner, links), not its identity, achievements or presence, which every visitor already sees on
 * /joueurs/{slug}. Filtering on it here would hide every member without a community_profile row - i.e.
 * most of them - from exactly the anonymous visitor this page is built for.
 *
 * **What is said about them** is gated: a running session's game is only named when the viewer may know
 * it (ViewableSessionsQuery, which delegates to the single audience rule). A private personal run shows
 * its player as "en jeu" and nothing more.
 */
final readonly class CommunityOverviewQuery
{
    private const int PLAYING_LIMIT = 12;
    private const int RECENT_ACHIEVEMENTS_LIMIT = 8;

    public function __construct(
        private CommunityDirectoryQueryInterface $directory,
        private CommunityPresenceQueryInterface $presence,
        private RecentAchievementGrantsQueryInterface $grants,
        private CommunityUserDirectoryQueryInterface $cards,
        private AchievementDefinitionRepositoryInterface $definitions,
        private AchievementImageUrlResolver $achievementImages,
        private ViewableSessionsQuery $viewableSessions,
    ) {
    }

    /**
     * @return array{
     *     memberCount: int,
     *     playingNow: list<array{slug: string, displayName: string|null, avatarUrl: string|null, game: string|null}>,
     *     recentAchievements: list<array{achievementKey: string, name: string, imageUrl: string|null, unlockedAt: string, slug: string, displayName: string|null, avatarUrl: string|null}>
     * }
     */
    public function forViewer(?string $viewerId): array
    {
        $playingRows = $this->presence->playingNow(self::PLAYING_LIMIT);
        $grantRows = $this->grants->recent(self::RECENT_ACHIEVEMENTS_LIMIT);

        // One card read for both lists - the same member is routinely in each.
        $cards = $this->cards->cards(array_values(array_unique([
            ...array_map(static fn (array $row): string => $row['userId'], $playingRows),
            ...array_map(static fn (array $row): string => $row['userId'], $grantRows),
        ])));

        return [
            'memberCount' => $this->directory->listableMemberCount(),
            'playingNow' => $this->presentPlaying($playingRows, $cards, $viewerId),
            'recentAchievements' => $this->presentAchievements($grantRows, $cards),
        ];
    }

    /**
     * @param list<array{userId: string, sessionId: string, game: string|null}>                                    $rows
     * @param array<string, array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null}> $cards
     *
     * @return list<array{slug: string, displayName: string|null, avatarUrl: string|null, game: string|null}>
     */
    private function presentPlaying(array $rows, array $cards, ?string $viewerId): array
    {
        $viewable = $this->viewableSessions->forViewer(
            array_values(array_unique(array_map(static fn (array $row): string => $row['sessionId'], $rows))),
            $viewerId,
        );

        $out = [];
        foreach ($rows as $row) {
            $card = $cards[$row['userId']] ?? null;
            if (null === $card) {
                continue;
            }
            $out[] = [
                'slug' => $card['slug'],
                'displayName' => $card['displayName'],
                'avatarUrl' => $card['avatarUrl'],
                // Named only when this viewer may know what is being played; "en jeu" otherwise.
                'game' => ($viewable[$row['sessionId']] ?? false) ? $row['game'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{userId: string, achievementKey: string, unlockedAt: string}>                              $rows
     * @param array<string, array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null}> $cards
     *
     * @return list<array{achievementKey: string, name: string, imageUrl: string|null, unlockedAt: string, slug: string, displayName: string|null, avatarUrl: string|null}>
     */
    private function presentAchievements(array $rows, array $cards): array
    {
        // Deactivated definitions are included on purpose: the unlock happened, and the profile keeps
        // showing it (achievements are monotonic). Only a definition deleted outright drops a row.
        $byKey = [];
        foreach ($this->definitions->all() as $definition) {
            $byKey[$definition->getKey()] = $definition;
        }

        $out = [];
        foreach ($rows as $row) {
            $definition = $byKey[$row['achievementKey']] ?? null;
            $card = $cards[$row['userId']] ?? null;
            if (null === $definition || null === $card) {
                continue;
            }
            $out[] = [
                'achievementKey' => $row['achievementKey'],
                'name' => $definition->getName(),
                'imageUrl' => $this->achievementImages->resolve($definition->getCustomImageKey()),
                'unlockedAt' => $row['unlockedAt'],
                'slug' => $card['slug'],
                'displayName' => $card['displayName'],
                'avatarUrl' => $card['avatarUrl'],
            ];
        }

        return $out;
    }
}
