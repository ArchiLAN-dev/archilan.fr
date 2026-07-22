<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Domain\Repository\BlockRepositoryInterface;
use App\Community\Domain\Repository\CommunityProfileRepositoryInterface;
use App\Community\Domain\Repository\FriendshipRepositoryInterface;
use App\Community\Domain\Service\AudiencePolicy;
use App\Community\Domain\ValueObject\Audience;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;

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

        // Deliberately MEMBERS and not Audience::DEFAULT (story 30.28). A missing row means an account
        // that never engaged with the profile surface at all - rows are only created lazily on a self
        // view or a save. Reading the new public default here would publish dormant accounts that never
        // did anything, which is exactly what keeping existing profiles untouched was meant to avoid.
        // The public default applies to rows as they are created, not to the absence of a row.
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
