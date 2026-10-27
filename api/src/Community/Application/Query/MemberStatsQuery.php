<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Application\Support\MemberStatsLayout;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\Entity\MemberStatsSnapshot;
use App\Community\Domain\Repository\MemberStatsSnapshotRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Application\Query\PlayerGameStatsQueryInterface;

/**
 * The public stats page of a member (story 30.55): every fact the achievements track, grouped by theme, plus the
 * connected time and a table by game.
 *
 * The facts come from the snapshot the hourly achievement recompute keeps, never built here: the page costs a
 * read, the play-time query and the by-game queries. A member never recomputed reads as all zeros, with a null
 * `computedAt`. Same audience as the achievements catalogue: anyone, for any live profile.
 *
 * @phpstan-type StatFact array{key: string, label: string, value: int, unit: string|null}
 * @phpstan-type GameRow array{gameId: string, name: string, slug: string|null, runs: int, goals: int, checks: int, playedSeconds: int, deaths: int}
 */
final readonly class MemberStatsQuery
{
    public function __construct(
        private CommunityProfileQueryInterface $profiles,
        private MemberStatsSnapshotRepositoryInterface $snapshots,
        private PlayTimeStatsQueryInterface $playTime,
        private PlayerGameStatsQueryInterface $gameStats,
        private GameActivityQueryInterface $gameActivity,
        private GameRepositoryInterface $games,
    ) {
    }

    /**
     * @return array{
     *     slug: string,
     *     displayName: string|null,
     *     computedAt: string|null,
     *     groups: list<array{key: string, label: string, facts: list<StatFact>}>,
     *     superlatives: list<array{key: string, label: string, value: int}>,
     *     games: list<GameRow>
     * }|null
     */
    public function forSlug(string $slug): ?array
    {
        $profile = $this->profiles->forSlug($slug);
        if (null === $profile) {
            return null;
        }
        $userId = $profile['userId'];
        $snapshot = $this->snapshots->findByUserId($userId);
        $facts = $snapshot instanceof MemberStatsSnapshot ? $snapshot->getFacts() : [];
        $labels = AchievementMetricCatalog::facts();

        $groups = [];
        foreach (MemberStatsLayout::groups() as $group) {
            $groups[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'facts' => array_map(static fn (string $fact): array => self::fact($fact, $labels, $facts), $group['facts']),
            ];
            if ('games' === $group['key']) {
                $groups[] = $this->playTimeGroup($userId);
            }
        }
        $others = MemberStatsLayout::otherFacts();
        if ([] !== $others) {
            $groups[] = [
                'key' => MemberStatsLayout::OTHER_GROUP,
                'label' => 'Autres',
                'facts' => array_map(static fn (string $fact): array => self::fact($fact, $labels, $facts), $others),
            ];
        }

        $superlatives = [];
        foreach (MemberStatsLayout::superlativeFacts() as $fact) {
            $superlatives[] = [
                'key' => substr($fact, \strlen(AchievementMetricCatalog::SUPERLATIVE_PREFIX)),
                'label' => $labels[$fact] ?? $fact,
                'value' => $facts[$fact] ?? 0,
            ];
        }

        return [
            'slug' => $profile['slug'],
            'displayName' => $profile['displayName'],
            'computedAt' => $snapshot?->getComputedAt()->format(\DateTimeInterface::ATOM),
            'groups' => $groups,
            'superlatives' => $superlatives,
            'games' => $this->gameRows($userId),
        ];
    }

    /**
     * @return array{key: string, label: string, facts: list<StatFact>}
     */
    private function playTimeGroup(string $userId): array
    {
        $stats = $this->playTime->forUser($userId);

        return [
            'key' => 'playtime',
            'label' => 'Temps de jeu',
            'facts' => [
                ['key' => 'playedSeconds', 'label' => 'Temps connecté sur les parties (total)', 'value' => $stats->totalSeconds, 'unit' => 'seconds'],
                ['key' => 'longestPlaySpanSeconds', 'label' => 'Plus longue session de jeu d\'une traite', 'value' => $stats->longestSpanSeconds, 'unit' => 'seconds'],
                ['key' => 'maxPlayedSecondsInSession', 'label' => 'Temps connecté sur une même partie (record)', 'value' => $stats->maxSessionSeconds, 'unit' => 'seconds'],
            ],
        ];
    }

    /**
     * @param array<string, string> $labels
     * @param array<string, int>    $facts
     *
     * @return StatFact
     */
    private static function fact(string $fact, array $labels, array $facts): array
    {
        return ['key' => $fact, 'label' => $labels[$fact] ?? $fact, 'value' => $facts[$fact] ?? 0, 'unit' => null];
    }

    /**
     * @return list<GameRow>
     */
    private function gameRows(string $userId): array
    {
        $counts = $this->gameStats->perGameForUser($userId);
        $activity = $this->gameActivity->forUser($userId);
        $ids = array_values(array_unique([...array_keys($counts), ...array_keys($activity)]));
        if ([] === $ids) {
            return [];
        }

        $games = [];
        foreach ($this->games->findByIds($ids) as $game) {
            $games[$game->getId()] = $game;
        }

        $rows = [];
        foreach ($ids as $id) {
            $count = $counts[$id] ?? ['runs' => 0, 'goals' => 0, 'checks' => 0];
            $active = $activity[$id] ?? ['playedSeconds' => 0, 'deaths' => 0];
            if (0 === $count['runs'] && 0 === $active['playedSeconds'] && 0 === $active['deaths']) {
                continue;
            }
            $game = $games[$id] ?? null;
            $rows[] = [
                'gameId' => $id,
                'name' => $game?->getName() ?? 'Jeu inconnu',
                'slug' => $game?->getSlug(),
                'runs' => $count['runs'],
                'goals' => $count['goals'],
                'checks' => $count['checks'],
                'playedSeconds' => $active['playedSeconds'],
                'deaths' => $active['deaths'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['playedSeconds'], $b['runs'], $a['name']] <=> [$a['playedSeconds'], $a['runs'], $b['name']]);

        return $rows;
    }
}
