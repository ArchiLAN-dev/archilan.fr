<?php

declare(strict_types=1);

namespace App\Streaming\Application\Query;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Sessions\Application\Support\SessionRecapAudience;
use App\Streaming\Application\Port\TwitchApiClientInterface;
use App\Streaming\Application\Service\LocalStreamsState;
use App\Streaming\Application\Support\LiveTwitchLogins;
use App\Streaming\Application\Support\MediaPublishCredentials;
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
 * @phpstan-type BroadcastTwitchStream array{kind: 'twitch', userId: string, slug: string, displayName: string|null, twitchLogin: string, avatarUrl: string|null, live: bool, viewerCount: int|null}
 * @phpstan-type BroadcastLocalStream array{kind: 'local', userId: string, slug: string, displayName: string|null, whepUrl: string}
 */
final readonly class ParticipantStreamsView
{
    public function __construct(
        private ParticipantTwitchLinksQueryInterface $query,
        private LiveTwitchLogins $liveLogins,
        private TwitchApiClientInterface $client,
        private CacheInterface $cache,
        private SessionRecapAudience $audience,
        private EventRepositoryInterface $events,
        private LocalStreamsState $localStreams,
        private MediaPublishCredentials $credentials,
    ) {
    }

    /**
     * Story 50.7: null too for a viewer the event is hidden from, who cannot tell it from an unknown id.
     *
     * @return list<ParticipantStream>|null
     */
    public function forEvent(string $eventId, ?string $viewerId): ?array
    {
        return $this->audience->hidesEvent($eventId, $viewerId) ? null : $this->present($this->query->forEvent($eventId));
    }

    /**
     * Story 50.7: the same rule as the page of the run.
     *
     * @return list<ParticipantStream>|null
     */
    public function forPersonalRun(string $runId, ?string $viewerId): ?array
    {
        return $this->audience->hidesRun($runId, $viewerId) ? null : $this->present($this->query->forPersonalRun($runId));
    }

    /**
     * Story 47.1: what the broadcast may show, the live streams of the members who agreed to it. Story 47.8: first the
     * screens the régie receives on the LAN media server (`local`), then the Twitch lives (`twitch`); a member on
     * both shows once, from the LAN (its latency, and no need for Internet).
     *
     * @return list<BroadcastLocalStream|BroadcastTwitchStream>|null
     */
    public function forEventBroadcast(string $eventId): ?array
    {
        $streams = $this->present($this->query->forEventBroadcast($eventId));
        if (null === $streams) {
            return null;
        }

        $local = $this->localBroadcast($eventId);
        $onLan = array_flip(array_map(static fn (array $stream): string => $stream['userId'], $local));
        $twitch = [];
        foreach ($streams as $stream) {
            if ($stream['live'] && !isset($onLan[$stream['userId']])) {
                $twitch[] = ['kind' => 'twitch', ...$stream];
            }
        }

        return [...$local, ...$twitch];
    }

    /** @return list<BroadcastLocalStream> */
    private function localBroadcast(string $eventId): array
    {
        $host = $this->events->findById($eventId)?->getMediaServerHost();
        if (null === $host) {
            return [];
        }
        $live = $this->localStreams->liveRegistrations($eventId);
        if ([] === $live) {
            return [];
        }

        $streams = [];
        foreach ($this->query->localBroadcastRegistrants($eventId) as $registrant) {
            if (!isset($live[$registrant['registrationId']])) {
                continue;
            }
            $streams[] = [
                'kind' => 'local',
                'userId' => $registrant['userId'],
                'slug' => $registrant['slug'],
                'displayName' => $registrant['displayName'],
                'whepUrl' => MediaPublishCredentials::whepUrl($host, $this->credentials->pathFor($registrant['registrationId'])),
            ];
        }
        usort($streams, static fn (array $a, array $b): int => mb_strtolower($a['displayName'] ?? $a['slug']) <=> mb_strtolower($b['displayName'] ?? $b['slug']));

        return $streams;
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
        $liveMap = $this->liveLogins->among($logins);
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
