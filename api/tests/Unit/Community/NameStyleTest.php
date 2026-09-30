<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Enum\NameStyle;
use PHPUnit\Framework\TestCase;

/**
 * Story 30.44. The holographic name follows the account's status at read time: gold for an admin, silver for a
 * member, nothing otherwise or when the owner turned it off.
 */
final class NameStyleTest extends TestCase
{
    public function testAnAdminIsGoldMemberOrNot(): void
    {
        self::assertSame(NameStyle::Gold, NameStyle::for(isAdmin: true, isMember: false, enabled: true));
        self::assertSame(NameStyle::Gold, NameStyle::for(isAdmin: true, isMember: true, enabled: true));
    }

    public function testAMemberIsSilver(): void
    {
        self::assertSame(NameStyle::Silver, NameStyle::for(isAdmin: false, isMember: true, enabled: true));
    }

    public function testNeitherHasNone(): void
    {
        self::assertNull(NameStyle::for(isAdmin: false, isMember: false, enabled: true));
    }

    public function testTurnedOffHasNone(): void
    {
        self::assertNull(NameStyle::for(isAdmin: true, isMember: true, enabled: false));
    }
}
