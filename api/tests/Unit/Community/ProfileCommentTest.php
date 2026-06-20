<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\ProfileComment;
use PHPUnit\Framework\TestCase;

final class ProfileCommentTest extends TestCase
{
    public function testOwnershipAndProfileChecks(): void
    {
        $comment = ProfileComment::create('owner', 'author', 'Nice profile!', new \DateTimeImmutable());

        self::assertTrue($comment->isAuthor('author'));
        self::assertFalse($comment->isAuthor('owner'));
        self::assertTrue($comment->isOnProfileOf('owner'));
        self::assertFalse($comment->isOnProfileOf('author'));
        self::assertFalse($comment->isHidden());
    }

    public function testHideIsIdempotent(): void
    {
        $comment = ProfileComment::create('owner', 'author', 'Hi', new \DateTimeImmutable());
        $first = new \DateTimeImmutable('2026-06-18T10:00:00+00:00');
        $comment->hide($first);
        $comment->hide(new \DateTimeImmutable('2026-06-19T10:00:00+00:00'));

        self::assertTrue($comment->isHidden());
        self::assertEquals($first, $comment->getHiddenAt());
    }
}
