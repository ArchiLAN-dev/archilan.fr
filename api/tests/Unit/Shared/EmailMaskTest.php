<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\EmailMask;
use PHPUnit\Framework\TestCase;

final class EmailMaskTest extends TestCase
{
    public function testAnAddressKeepsItsFirstLetterAndDomain(): void
    {
        self::assertSame('a***@example.org', EmailMask::mask('alice.martin@example.org'));
    }

    public function testAnAccentedFirstLetterStaysWhole(): void
    {
        self::assertSame('é***@example.org', EmailMask::mask('élodie@example.org'));
    }

    public function testWhatIsNotAnAddressShowsNothing(): void
    {
        self::assertSame('***', EmailMask::mask('alice'));
        self::assertSame('***', EmailMask::mask('@example.org'));
    }
}
