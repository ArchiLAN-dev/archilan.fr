<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ActivityEntry;
use App\Community\Domain\ActivityEntryRepositoryInterface;
use App\Community\Domain\FriendshipRepositoryInterface;
use App\Community\Domain\Kudos;
use App\Community\Domain\KudosRepositoryInterface;

/**
 * Reads the activity feed (story 30.9). Visibility is resolved at read time per actor via the shared
 * ProfileVisibility (audience vs viewer tier, block overrides) - never stored on the entry.
 */
final readonly class CommunityFeedQuery
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 100;

    public function __construct(
        private ActivityEntryRepositoryInterface $entries,
        private FriendshipRepositoryInterface $friendships,
        private ProfileVisibility $visibility,
        private CommunityUserDirectoryQueryInterface $directory,
        private KudosRepositoryInterface $kudos,
        private CommunityPresenceQueryInterface $presence,
    ) {
    }

    /**
     * One actor's activity, visible to the viewer (or [] if the viewer may not see it).
     *
     * @return list<array<string, mixed>>
     */
    public function forActor(string $actorId, ?string $viewerId, int $limit, ?\DateTimeImmutable $before): array
    {
        if (!$this->visibility->canSee($viewerId, $actorId)) {
            return [];
        }

        $entries = $this->entries->recentForActors([$actorId], $this->clampLimit($limit), $before);

        return $this->present($entries, false, $viewerId);
    }

    /**
     * The viewer's feed: their own + their friends' activity (all friend/self tier, so audience-visible).
     *
     * @return list<array<string, mixed>>
     */
    public function feed(string $viewerId, int $limit, ?\DateTimeImmutable $before): array
    {
        $actorIds = [$viewerId];
        foreach ($this->friendships->findAccepted($viewerId) as $friendship) {
            $actorIds[] = $friendship->otherParty($viewerId);
        }

        $entries = $this->entries->recentForActors(array_values(array_unique($actorIds)), $this->clampLimit($limit), $before);

        return $this->present($entries, true, $viewerId);
    }

    /**
     * @param list<ActivityEntry> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function present(array $entries, bool $withActor, ?string $viewerId): array
    {
        // Resolve every referenced user (actors + friendship counterparts) in one query.
        $userIds = [];
        $runEntryIds = [];
        foreach ($entries as $entry) {
            $userIds[] = $entry->getActorId();
            $withUserId = $entry->getPayload()['withUserId'] ?? null;
            if (is_string($withUserId)) {
                $userIds[] = $withUserId;
            }
            if (ActivityEntry::TYPE_RUN_FINISHED === $entry->getType()) {
                $runEntryIds[] = $entry->getId();
            }
        }
        $cards = [] === $userIds ? [] : $this->directory->cards(array_values(array_unique($userIds)));

        // "Currently playing" presence for the rendered actors (feed only; the profile-activity view has
        // no actor row).
        $playing = [];
        if ($withActor) {
            $actorIds = array_values(array_unique(array_map(static fn (ActivityEntry $e): string => $e->getActorId(), $entries)));
            $playing = $this->presence->playing($actorIds);
        }

        // Kudos on run entries (one batch count + the viewer's given set).
        $kudosCounts = $this->kudos->countsFor(Kudos::TARGET_RUN, $runEntryIds);
        $kudosGiven = null === $viewerId ? [] : array_flip($this->kudos->givenBy($viewerId, Kudos::TARGET_RUN, $runEntryIds));

        $items = [];
        foreach ($entries as $entry) {
            $payload = $entry->getPayload();
            // A run can be kudos'd by anyone but its own actor (no self-kudos, story 30.11).
            $canKudos = ActivityEntry::TYPE_RUN_FINISHED === $entry->getType() && $entry->getActorId() !== $viewerId;
            $item = [
                'type' => $entry->getType(),
                'occurredAt' => $entry->getOccurredAt()->format(\DateTimeInterface::ATOM),
                'game' => is_string($payload['game'] ?? null) ? $payload['game'] : null,
                'event' => is_string($payload['event'] ?? null) ? $payload['event'] : null,
                'sessionId' => is_string($payload['sessionId'] ?? null) ? $payload['sessionId'] : null,
                'withSlug' => null,
                'withName' => null,
                'kudosTargetType' => $canKudos ? Kudos::TARGET_RUN : null,
                'kudosTargetId' => $canKudos ? $entry->getId() : null,
                'kudosCount' => $canKudos ? ($kudosCounts[$entry->getId()] ?? 0) : 0,
                'viewerHasKudos' => $canKudos && isset($kudosGiven[$entry->getId()]),
            ];

            $withUserId = $payload['withUserId'] ?? null;
            if (is_string($withUserId) && isset($cards[$withUserId])) {
                $item['withSlug'] = $cards[$withUserId]['slug'];
                $item['withName'] = $cards[$withUserId]['displayName'];
            }

            if ($withActor) {
                $card = $cards[$entry->getActorId()] ?? null;
                $item['actor'] = null === $card ? null : [
                    'slug' => $card['slug'],
                    'displayName' => $card['displayName'],
                    'avatarUrl' => $card['avatarUrl'],
                    'playing' => isset($playing[$entry->getActorId()]),
                ];
            }

            $items[] = $item;
        }

        return $items;
    }

    private function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }
}
