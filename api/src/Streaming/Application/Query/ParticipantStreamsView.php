<?php

declare(strict_types=1);

namespace App\Streaming\Application\Query;

use App\Streaming\Application\Port\TwitchApiClientInterface;
use App\Streaming\Domain\Service\TwitchLinkResolver;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Facade composing the per-session participant list with their Twitch live status.
 *
 * Loads the participants of a session, resolves each one's Twitch login from their social links, batch-checks
 * which are live (one Helix call, cached), and returns them sorted live-first. Returns null when the session
 * does not exist (distinct from an existing session with no streamers, which yields an empty list).
 *
 * @phpstan-type ParticipantStream array{userId: string, slug: string, displayName: string|null, twitchLogin: string, avatarUrl: string|null, live: bool, viewerCount: int|null}
 */
final readonly class ParticipantStreamsView
{
    public function __construct(
        private ParticipantTwitchLinksQueryInterface $query,
        private TwitchApiClientInterface $client,
        private CacheInterface $cache,
    ) {
    }

    /** @return list<ParticipantStream>|null */
    public function forEvent(string $eventId): ?array
    {
        return $this->present($this->query->forEvent($eventId));
    }

    /** @return list<ParticipantStream>|null */
    public function forPersonalRun(string $runId): ?array
    {
        return $this->present($this->query->forPersonalRun($runId));
    }

    /** @return list<ParticipantStream>|null */
    public function forWeeklyRun(string $weeklyRunId): ?array
    {
        return $this->present($this->query->forWeeklyRun($weeklyRunId));
    }

    /**
     * @param list<array{userId: string, slug: string, displayName: string|null, socialLinks: list<array{label: string, url: string}>}>|null $rows
     *
     * @return list<ParticipantStream>|null
     */
    private function present(?array $rows): ?array
    {
        if (null === $rows) {
            return null;
        }

        $participants = [];
        foreach ($rows as $row) {
            $login = TwitchLinkResolver::resolveLogin($row['socialLinks']);
            if (null === $login) {
                continue;
            }
            $participants[] = [
                'userId' => $row['userId'],
                'slug' => $row['slug'],
                'displayName' => $row['displayName'],
                'twitchLogin' => $login,
            ];
        }

        if ([] === $participants) {
            return [];
        }

        $logins = array_map(static fn (array $p): string => $p['twitchLogin'], $participants);
        $liveMap = $this->liveMap($logins);
        $avatarMap = $this->avatarMap($logins);

        $streams = array_map(
            static function (array $p) use ($liveMap, $avatarMap): array {
                $live = array_key_exists($p['twitchLogin'], $liveMap);

                return [
                    'userId' => $p['userId'],
                    'slug' => $p['slug'],
                    'displayName' => $p['displayName'],
                    'twitchLogin' => $p['twitchLogin'],
                    'avatarUrl' => $avatarMap[$p['twitchLogin']] ?? null,
                    'live' => $live,
                    'viewerCount' => $live ? $liveMap[$p['twitchLogin']] : null,
                ];
            },
            $participants,
        );

        usort($streams, static function (array $a, array $b): int {
            if ($a['live'] !== $b['live']) {
                return $a['live'] ? -1 : 1;
            }
            $aName = mb_strtolower($a['displayName'] ?? $a['slug']);
            $bName = mb_strtolower($b['displayName'] ?? $b['slug']);

            return ($aName <=> $bName) ?: ($a['slug'] <=> $b['slug']);
        });

        return $streams;
    }

    /**
     * Cached batch live check keyed by the sorted login set, so concurrent session pages sharing streamers
     * reuse one Helix call within the TTL. A Twitch outage (null from the client) is cached as "nobody live"
     * for only 15s so a transient failure self-heals fast, instead of pinning everyone offline for the full
     * 60s TTL reserved for authoritative results (deferred item from story 7.7, resolved in story 33.8).
     *
     * @param list<string> $logins
     *
     * @return array<string, int>
     */
    private function liveMap(array $logins): array
    {
        $unique = array_values(array_unique($logins));
        sort($unique);
        $key = 'streaming.participant_streams.live.'.md5(implode(',', $unique));

        return $this->cache->get($key, function (ItemInterface $item) use ($unique): array {
            $result = $this->client->fetchLiveLogins($unique);

            if (null === $result) {
                $item->expiresAfter(15);

                return [];
            }

            $item->expiresAfter(60);

            return $result;
        });
    }

    /**
     * Cached batch avatar lookup keyed by the sorted login set. Profile images change rarely, so a long TTL
     * keeps the extra Helix /users call cheap.
     *
     * @param list<string> $logins
     *
     * @return array<string, string>
     */
    private function avatarMap(array $logins): array
    {
        $unique = array_values(array_unique($logins));
        sort($unique);
        $key = 'streaming.participant_streams.avatars.'.md5(implode(',', $unique));

        return $this->cache->get($key, function (ItemInterface $item) use ($unique): array {
            $item->expiresAfter(3600);

            return $this->client->fetchAvatars($unique);
        });
    }
}
