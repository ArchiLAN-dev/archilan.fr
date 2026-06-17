<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\CommunityProfile;
use PHPUnit\Framework\TestCase;

final class CommunityProfileTest extends TestCase
{
    public function testNewProfileHasNoAvatarAndIsStale(): void
    {
        $profile = CommunityProfile::create('user-1', new \DateTimeImmutable('2026-06-17T10:00:00+00:00'));

        self::assertNull($profile->getAvatarUrl());
        self::assertNull($profile->getAvatarResolvedAt());
        self::assertTrue($profile->isAvatarStale(new \DateTimeImmutable('2026-06-17T10:00:00+00:00'), 3600));
    }

    public function testCacheAvatarStoresUrlAndTimestamp(): void
    {
        $profile = CommunityProfile::create('user-1', new \DateTimeImmutable('2026-06-17T10:00:00+00:00'));
        $now = new \DateTimeImmutable('2026-06-18T10:00:00+00:00');

        $profile->cacheAvatar('https://cdn/avatar.png', $now);

        self::assertSame('https://cdn/avatar.png', $profile->getAvatarUrl());
        self::assertEquals($now, $profile->getAvatarResolvedAt());
        self::assertFalse($profile->isAvatarStale($now, 3600));
        self::assertTrue($profile->isAvatarStale($now->modify('+2 hours'), 3600));
    }

    public function testCachingNullStillRecordsResolutionToThrottleRetries(): void
    {
        $profile = CommunityProfile::create('user-1', new \DateTimeImmutable('2026-06-17T10:00:00+00:00'));
        $now = new \DateTimeImmutable('2026-06-18T10:00:00+00:00');

        $profile->cacheAvatar(null, $now);

        self::assertNull($profile->getAvatarUrl());
        self::assertFalse($profile->isAvatarStale($now, 3600), 'a null result is still a resolution');
    }
}
