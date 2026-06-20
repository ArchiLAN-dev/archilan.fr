<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Audience;
use App\Community\Domain\AudiencePolicy;
use App\Community\Domain\BlockRepositoryInterface;
use App\Community\Domain\CommunityProfileRepositoryInterface;
use App\Community\Domain\FriendshipRepositoryInterface;
use App\Membership\Application\ActiveMembershipQueryInterface;

/**
 * Resolves whether a viewer may see an owner's social surface: the viewer tier (self/friend/member/
 * authenticated/anonymous, `member` = live `IS_MEMBER`) vs the owner's profile audience, with block
 * overriding everything. Shared by the comments read (story 30.10); mirrors the gating used by the
 * profile read and the feed.
 */
final readonly class ProfileVisibility
{
    public function __construct(
        private FriendshipRepositoryInterface $friendships,
        private BlockRepositoryInterface $blocks,
        private ActiveMembershipQueryInterface $memberships,
        private CommunityProfileRepositoryInterface $profiles,
    ) {
    }

    public function canSee(?string $viewerId, string $ownerId): bool
    {
        if ($viewerId === $ownerId) {
            return true;
        }
        if (null !== $viewerId && $this->blocks->existsEitherWay($viewerId, $ownerId)) {
            return false;
        }

        $audience = $this->profiles->findByUserId($ownerId)?->getAudience() ?? Audience::MEMBERS;

        return AudiencePolicy::canView($this->tier($viewerId, $ownerId), $audience);
    }

    public function tier(?string $viewerId, string $ownerId): string
    {
        if (null === $viewerId) {
            return AudiencePolicy::TIER_ANONYMOUS;
        }
        if ($viewerId === $ownerId) {
            return AudiencePolicy::TIER_SELF;
        }
        if ($this->friendships->areFriends($viewerId, $ownerId)) {
            return AudiencePolicy::TIER_FRIEND;
        }
        if ($this->memberships->hasActiveMembership($viewerId)) {
            return AudiencePolicy::TIER_MEMBER;
        }

        return AudiencePolicy::TIER_AUTHENTICATED;
    }
}
