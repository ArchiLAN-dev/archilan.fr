<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Application\Support\PartnerLinkCode;
use App\Content\Domain\Entity\TrackedLink;
use PHPUnit\Framework\TestCase;

/** Story 55.3: the code of a partner's link always fits the link code rule. */
final class PartnerLinkCodeTest extends TestCase
{
    public function testTheCodeComesFromTheNameThenANumber(): void
    {
        self::assertSame('partenaire-ludotheque-de-lille', PartnerLinkCode::candidate('Ludothèque de Lille', 1));
        self::assertSame('partenaire-ludotheque-de-lille-2', PartnerLinkCode::candidate('Ludothèque de Lille', 2));
        self::assertSame('partenaire', PartnerLinkCode::candidate('!!!', 1));
        self::assertSame('partenaire-3', PartnerLinkCode::candidate('!!!', 3));
    }

    public function testALongNameIsCutWithoutADashAtTheEnd(): void
    {
        $name = 'Association des joueurs de jeux de societe du Nord';
        foreach ([1, 2, 12] as $attempt) {
            $code = PartnerLinkCode::candidate($name, $attempt);
            self::assertLessThanOrEqual(40, strlen($code));
            self::assertTrue(TrackedLink::isValidCode($code), $code);
        }
        self::assertSame('partenaire-association-des-joueurs-de-12', PartnerLinkCode::candidate($name, 12));
    }
}
