<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Audience;
use App\Community\Domain\AudiencePolicy;
use PHPUnit\Framework\TestCase;

final class AudiencePolicyTest extends TestCase
{
    public function testPublicIsVisibleToEveryTier(): void
    {
        foreach ([AudiencePolicy::TIER_ANONYMOUS, AudiencePolicy::TIER_AUTHENTICATED, AudiencePolicy::TIER_MEMBER, AudiencePolicy::TIER_FRIEND, AudiencePolicy::TIER_SELF] as $tier) {
            self::assertTrue(AudiencePolicy::canView($tier, Audience::PUBLIC));
        }
    }

    public function testMembersRequiresAtLeastMember(): void
    {
        self::assertFalse(AudiencePolicy::canView(AudiencePolicy::TIER_ANONYMOUS, Audience::MEMBERS));
        self::assertFalse(AudiencePolicy::canView(AudiencePolicy::TIER_AUTHENTICATED, Audience::MEMBERS));
        self::assertTrue(AudiencePolicy::canView(AudiencePolicy::TIER_MEMBER, Audience::MEMBERS));
        self::assertTrue(AudiencePolicy::canView(AudiencePolicy::TIER_FRIEND, Audience::MEMBERS));
        self::assertTrue(AudiencePolicy::canView(AudiencePolicy::TIER_SELF, Audience::MEMBERS));
    }

    public function testFriendsRequiresFriendOrSelf(): void
    {
        self::assertFalse(AudiencePolicy::canView(AudiencePolicy::TIER_MEMBER, Audience::FRIENDS));
        self::assertTrue(AudiencePolicy::canView(AudiencePolicy::TIER_FRIEND, Audience::FRIENDS));
        self::assertTrue(AudiencePolicy::canView(AudiencePolicy::TIER_SELF, Audience::FRIENDS));
    }
}
