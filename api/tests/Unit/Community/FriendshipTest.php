<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Friendship;
use PHPUnit\Framework\TestCase;

final class FriendshipTest extends TestCase
{
    public function testPairKeyIsOrderIndependent(): void
    {
        self::assertSame(Friendship::pairKey('a', 'b'), Friendship::pairKey('b', 'a'));
        self::assertNotSame(Friendship::pairKey('a', 'b'), Friendship::pairKey('a', 'c'));
    }

    public function testRequestStartsPendingAndTracksParties(): void
    {
        $f = Friendship::request('alice', 'bob', new \DateTimeImmutable());

        self::assertTrue($f->isPending());
        self::assertFalse($f->isAccepted());
        self::assertSame('alice', $f->getRequesterId());
        self::assertSame('bob', $f->getAddresseeId());
        self::assertTrue($f->involves('alice'));
        self::assertTrue($f->involves('bob'));
        self::assertFalse($f->involves('carol'));
        self::assertSame('bob', $f->otherParty('alice'));
        self::assertSame('alice', $f->otherParty('bob'));
        self::assertTrue($f->isAddressee('bob'));
        self::assertFalse($f->isAddressee('alice'));
    }

    public function testAcceptAndDeclineTransition(): void
    {
        $now = new \DateTimeImmutable();

        $accepted = Friendship::request('alice', 'bob', $now);
        $accepted->accept($now);
        self::assertTrue($accepted->isAccepted());
        self::assertFalse($accepted->isPending());

        $declined = Friendship::request('alice', 'bob', $now);
        $declined->decline($now);
        self::assertFalse($declined->isPending());
        self::assertFalse($declined->isAccepted());
        self::assertSame(Friendship::DECLINED, $declined->getStatus());
    }
}
