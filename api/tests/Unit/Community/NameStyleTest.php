<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Enum\NameStyle;
use PHPUnit\Framework\TestCase;

/**
 * Story 30.44. The titled name follows the account's status at read time: legendary for an admin, epic for a
 * member, nothing otherwise or when the owner turned it off.
 */
final class NameStyleTest extends TestCase
{
    public function testAnAdminIsLegendaryMemberOrNot(): void
    {
        self::assertSame(NameStyle::Legendary, NameStyle::for(isAdmin: true, isMember: false, enabled: true));
        self::assertSame(NameStyle::Legendary, NameStyle::for(isAdmin: true, isMember: true, enabled: true));
    }

    public function testAMemberIsEpic(): void
    {
        self::assertSame(NameStyle::Epic, NameStyle::for(isAdmin: false, isMember: true, enabled: true));
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
