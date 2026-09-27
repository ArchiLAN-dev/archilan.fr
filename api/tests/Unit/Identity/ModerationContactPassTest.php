<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Support\AuthSessionSigner;
use App\Identity\Application\Support\ModerationContactPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Story 39.2: the pass a blocked member gets once their credentials checked out, good only for writing to
 * the moderation.
 */
final class ModerationContactPassTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-27 10:00:00');
    }

    public function testAPassNamesItsMember(): void
    {
        $pass = new ModerationContactPass('secret', $this->clock);

        self::assertSame('user-1', $pass->verify($pass->issue('user-1')));
    }

    public function testItLastsOneHour(): void
    {
        $pass = new ModerationContactPass('secret', $this->clock);
        $value = $pass->issue('user-1');

        $this->clock->modify('+59 minutes');
        self::assertSame('user-1', $pass->verify($value));
        $this->clock->modify('+2 minutes');
        self::assertNull($pass->verify($value));
    }

    public function testASessionIsNoPassAndAPassIsNoSession(): void
    {
        $pass = new ModerationContactPass('secret', $this->clock);
        $session = new AuthSessionSigner('secret', $this->clock);

        self::assertNull($pass->verify($session->sign('user-1')), 'a session cookie does not open the contact route');
        self::assertNull($session->verify($pass->issue('user-1')), 'a pass never logs anyone in');
    }

    public function testATamperedOrForeignPassIsRefused(): void
    {
        $pass = new ModerationContactPass('secret', $this->clock);
        [$payload, $signature] = explode('.', $pass->issue('user-1'));

        self::assertNull($pass->verify($payload.'.'.strrev($signature)));
        self::assertNull($pass->verify('garbage'));
        self::assertNull(new ModerationContactPass('other-secret', $this->clock)->verify($pass->issue('user-1')));
    }
}
