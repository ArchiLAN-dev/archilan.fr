<?php

declare(strict_types=1);

namespace App\Tests\Unit\SessionConfig;

use App\SessionConfig\Domain\ReleaseCollectMode;
use App\SessionConfig\Domain\SessionConfigOverride;
use App\SessionConfig\Domain\SessionConfigOverrideStore;
use PHPUnit\Framework\TestCase;

final class SessionConfigOverrideStoreTest extends TestCase
{
    public function testRoundTripsOverrideThroughJsonBlob(): void
    {
        $now = new \DateTimeImmutable('2026-06-17 10:00:00');
        $override = new SessionConfigOverride(releaseMode: ReleaseCollectMode::Goal);

        $store = new SessionConfigOverrideStore('sess-1', $override, $now);

        self::assertSame('sess-1', $store->sessionId());
        self::assertSame($now, $store->updatedAt());
        self::assertSame(ReleaseCollectMode::Goal, $store->toOverride()->releaseMode);
    }

    public function testUpdateReplacesOverrideAndBumpsUpdatedAt(): void
    {
        $now = new \DateTimeImmutable('2026-06-17 10:00:00');
        $store = new SessionConfigOverrideStore('sess-1', new SessionConfigOverride(), $now);

        $later = $now->modify('+30 minutes');
        $store->update(new SessionConfigOverride(collectMode: ReleaseCollectMode::Goal), $later);

        self::assertSame($later, $store->updatedAt());
        self::assertSame(ReleaseCollectMode::Goal, $store->toOverride()->collectMode);
        self::assertNull($store->toOverride()->releaseMode);
    }
}
