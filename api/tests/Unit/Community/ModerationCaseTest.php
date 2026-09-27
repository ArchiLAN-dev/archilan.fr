<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Entity\ModerationCase;
use PHPUnit\Framework\TestCase;

/**
 * Story 39.1: one moderation case per member, opened by their first sanction, reopened by every new one and
 * closed by a lift. Its forum post is created once and kept for good.
 */
final class ModerationCaseTest extends TestCase
{
    public function testAFirstSanctionOpensTheCase(): void
    {
        $case = ModerationCase::open('user-1', new \DateTimeImmutable('2026-09-27 10:00:00'));

        self::assertSame('user-1', $case->getTargetUserId());
        self::assertTrue($case->isOpen());
        self::assertNull($case->getForumThreadId());
    }

    public function testALiftClosesItAndANewSanctionReopensIt(): void
    {
        $case = ModerationCase::open('user-1', new \DateTimeImmutable('2026-09-27 10:00:00'));

        $case->close(new \DateTimeImmutable('2026-09-28 10:00:00'));
        self::assertFalse($case->isOpen());

        $case->reopen(new \DateTimeImmutable('2026-10-01 10:00:00'));
        self::assertTrue($case->isOpen());
        self::assertEquals(new \DateTimeImmutable('2026-10-01 10:00:00'), $case->getUpdatedAt());
    }

    public function testTheForumPostIsAttachedOnceAndKept(): void
    {
        $case = ModerationCase::open('user-1', new \DateTimeImmutable());

        $case->attachForumThread('thread-1');
        $case->attachForumThread('thread-2');

        self::assertSame('thread-1', $case->getForumThreadId(), 'a case keeps its post: one post per member');
    }

    public function testTheDirectMessageChannelStartsTheCursorAtTheBotsFirstMessage(): void
    {
        $case = ModerationCase::open('user-1', new \DateTimeImmutable());

        $case->attachDirectMessageChannel('dm-1', '1000');
        self::assertSame('dm-1', $case->getDirectMessageChannelId());
        self::assertSame('1000', $case->getDirectMessageCursor());

        // A later message of the bot never skips what the member wrote in between.
        $case->attachDirectMessageChannel('dm-1', '1200');
        self::assertSame('1000', $case->getDirectMessageCursor());
    }

    public function testTheCursorOnlyMovesForward(): void
    {
        $case = ModerationCase::open('user-1', new \DateTimeImmutable());
        $case->attachDirectMessageChannel('dm-1', '999');

        $case->advanceDirectMessageCursor('1001');
        $case->advanceDirectMessageCursor('1000');

        self::assertSame('1001', $case->getDirectMessageCursor(), 'snowflakes compare as numbers, not strings');
    }
}
