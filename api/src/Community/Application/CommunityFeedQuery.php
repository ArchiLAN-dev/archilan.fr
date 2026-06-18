<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ActivityEntry;
use App\Community\Domain\ActivityEntryRepositoryInterface;
use App\Community\Domain\Audience;
use App\Community\Domain\AudiencePolicy;
use App\Community\Domain\BlockRepositoryInterface;
use App\Community\Domain\CommunityProfileRepositoryInterface;
use App\Community\Domain\FriendshipRepositoryInterface;
use App\Membership\Application\ActiveMembershipQueryInterface;

/**
 * Reads the activity feed (story 30.9). Visibility is resolved at read time per actor (never stored on
 * the entry): an actor's profile audience vs the viewer's tier, with block overriding everything.
 */
final readonly class CommunityFeedQuery
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 100;

    public function __construct(
        private ActivityEntryRepositoryInterface $entries,
        private FriendshipRepositoryInterface $friendships,
        private BlockRepositoryInterface $blocks,
        private CommunityProfileRepositoryInterface $profiles,
        private ActiveMembershipQueryInterface $memberships,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    /**
     * One actor's activity, visible to the viewer (or [] if the viewer may not see it).
     *
     * @return list<array<string, mixed>>
     */
    public function forActor(string $actorId, ?string $viewerId, int $limit, ?\DateTimeImmutable $before): array
    {
        if (!$this->canSee($viewerId, $actorId)) {
            return [];
        }

        $entries = $this->entries->recentForActors([$actorId], $this->clampLimit($limit), $before);

        return $this->present($entries, withActor: false);
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

        return $this->present($entries, withActor: true);
    }

    private function canSee(?string $viewerId, string $actorId): bool
    {
        if ($viewerId === $actorId) {
            return true;
        }
        if (null !== $viewerId && $this->blocks->existsEitherWay($viewerId, $actorId)) {
            return false;
        }

        $profile = $this->profiles->findByUserId($actorId);
        $audience = $profile?->getAudience() ?? Audience::MEMBERS;

        return AudiencePolicy::canView($this->viewerTier($viewerId, $actorId), $audience);
    }

    private function viewerTier(?string $viewerId, string $actorId): string
    {
        if (null === $viewerId) {
            return AudiencePolicy::TIER_ANONYMOUS;
        }
        if ($viewerId === $actorId) {
            return AudiencePolicy::TIER_SELF;
        }
        if ($this->friendships->areFriends($viewerId, $actorId)) {
            return AudiencePolicy::TIER_FRIEND;
        }
        if ($this->memberships->hasActiveMembership($viewerId)) {
            return AudiencePolicy::TIER_MEMBER;
        }

        return AudiencePolicy::TIER_AUTHENTICATED;
    }

    /**
     * @param list<ActivityEntry> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function present(array $entries, bool $withActor): array
    {
        // Resolve every referenced user (actors + friendship counterparts) in one query.
        $userIds = [];
        foreach ($entries as $entry) {
            $userIds[] = $entry->getActorId();
            $withUserId = $entry->getPayload()['withUserId'] ?? null;
            if (is_string($withUserId)) {
                $userIds[] = $withUserId;
            }
        }
        $cards = [] === $userIds ? [] : $this->directory->cards(array_values(array_unique($userIds)));

        $items = [];
        foreach ($entries as $entry) {
            $payload = $entry->getPayload();
            $item = [
                'type' => $entry->getType(),
                'occurredAt' => $entry->getOccurredAt()->format(\DateTimeInterface::ATOM),
                'game' => is_string($payload['game'] ?? null) ? $payload['game'] : null,
                'event' => is_string($payload['event'] ?? null) ? $payload['event'] : null,
                'sessionId' => is_string($payload['sessionId'] ?? null) ? $payload['sessionId'] : null,
                'withSlug' => null,
                'withName' => null,
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
