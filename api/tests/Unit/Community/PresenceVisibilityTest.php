<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Enum\PresenceVisibility;
use App\Community\Domain\Service\AudiencePolicy;
use PHPUnit\Framework\TestCase;

final class PresenceVisibilityTest extends TestCase
{
    public function testTheMemberAlwaysSeesThemselves(): void
    {
        foreach (PresenceVisibility::cases() as $visibility) {
            self::assertTrue($visibility->shows(AudiencePolicy::TIER_SELF), $visibility->value);
        }
    }

    public function testEachSettingWidensFromNobodyToEveryone(): void
    {
        $tiers = [AudiencePolicy::TIER_ANONYMOUS, AudiencePolicy::TIER_AUTHENTICATED, AudiencePolicy::TIER_MEMBER, AudiencePolicy::TIER_FRIEND];
        $seen = static fn (PresenceVisibility $v): array => array_values(array_filter($tiers, $v->shows(...)));

        self::assertSame($tiers, $seen(PresenceVisibility::Everyone));
        self::assertSame([AudiencePolicy::TIER_MEMBER, AudiencePolicy::TIER_FRIEND], $seen(PresenceVisibility::Members));
        self::assertSame([AudiencePolicy::TIER_FRIEND], $seen(PresenceVisibility::Friends));
        self::assertSame([], $seen(PresenceVisibility::Nobody));
    }
}
