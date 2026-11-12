<?php

declare(strict_types=1);

namespace App\Tests\Unit\Streaming;

use App\Streaming\Domain\Entity\LocalStream;
use PHPUnit\Framework\TestCase;

/** Story 47.6: a stream is live while the régie agent keeps reporting it. */
final class LocalStreamTest extends TestCase
{
    public function testAStreamSeenOnlineStartsWhenMediaMtxSaysAndKeepsItsStart(): void
    {
        $now = new \DateTimeImmutable('2026-11-20T18:00:00+00:00');
        $stream = new LocalStream('id', 'event', 'reg', 'p-0123456789abcdef0123', $now);
        self::assertFalse($stream->isLiveAt($now));

        $stream->seenOnline($now->modify('-2 minutes'), $now);
        self::assertTrue($stream->isLiveAt($now));
        self::assertEquals($now->modify('-2 minutes'), $stream->getOnlineSince());

        $later = $now->modify('+10 seconds');
        $stream->seenOnline($later, $later);
        self::assertEquals($now->modify('-2 minutes'), $stream->getOnlineSince());
        self::assertEquals($later, $stream->getLastSeenAt());
    }

    public function testAStartInTheFutureOrMissingIsNow(): void
    {
        $now = new \DateTimeImmutable('2026-11-20T18:00:00+00:00');
        $stream = new LocalStream('id', 'event', 'reg', 'p-0123456789abcdef0123', $now);
        $stream->seenOnline($now->modify('+1 hour'), $now);
        self::assertEquals($now, $stream->getOnlineSince());

        $other = new LocalStream('id2', 'event', 'reg2', 'p-0123456789abcdef0124', $now);
        $other->seenOnline(null, $now);
        self::assertEquals($now, $other->getOnlineSince());
    }

    public function testACutStreamKeepsWhenItWasLastSeen(): void
    {
        $now = new \DateTimeImmutable('2026-11-20T18:00:00+00:00');
        $stream = new LocalStream('id', 'event', 'reg', 'p-0123456789abcdef0123', $now);
        $stream->seenOnline(null, $now);

        $stream->seenOffline();
        self::assertFalse($stream->isLiveAt($now));
        self::assertNull($stream->getOnlineSince());
        self::assertEquals($now, $stream->getLastSeenAt());

        $later = $now->modify('+1 minute');
        $stream->seenOnline(null, $later);
        self::assertEquals($later, $stream->getOnlineSince());
    }

    public function testWithoutAReportForThirtySecondsTheStreamIsNoLongerLive(): void
    {
        $now = new \DateTimeImmutable('2026-11-20T18:00:00+00:00');
        $stream = new LocalStream('id', 'event', 'reg', 'p-0123456789abcdef0123', $now);
        $stream->seenOnline(null, $now);

        self::assertTrue($stream->isLiveAt($now->modify('+30 seconds')));
        self::assertFalse($stream->isLiveAt($now->modify('+31 seconds')));

        // Back after a silence: a new start.
        $back = $now->modify('+2 minutes');
        $stream->seenOnline($now, $back);
        self::assertEquals($now, $stream->getOnlineSince());
    }

    /** v0.66.1: a session is stamped with the key version of when it was first seen. */
    public function testASessionStartedBeforeANewKeyIsFlagged(): void
    {
        $stream = new LocalStream('id', 'event', 'reg', 'p-0123456789abcdef0123', new \DateTimeImmutable());
        $stream->trackSession(null, 0);
        self::assertFalse($stream->startedWithAnOldKey(5));

        $stream->trackSession('session-1', 0);
        self::assertFalse($stream->startedWithAnOldKey(0));
        // The same session, after a new key: still stamped with the old one.
        $stream->trackSession('session-1', 1);
        self::assertTrue($stream->startedWithAnOldKey(1));

        $stream->trackSession('session-2', 1);
        self::assertFalse($stream->startedWithAnOldKey(1));
    }
}
