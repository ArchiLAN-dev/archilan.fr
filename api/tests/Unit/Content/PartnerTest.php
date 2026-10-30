<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Domain\Entity\Partner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Story 45.1: a partner of the association, shown to visitors once it has a logo. */
final class PartnerTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-30T12:00:00+01:00');
    }

    public function testCreateTrimsTheFields(): void
    {
        $partner = Partner::create('p1', '  Ludothèque  ', '  Des jeux à prêter.  ', ' https://ludo.example/ ', true, 2, $this->now);

        self::assertSame('Ludothèque', $partner->getName());
        self::assertSame('Des jeux à prêter.', $partner->getDescription());
        self::assertSame('https://ludo.example/', $partner->getUrl());
        self::assertTrue($partner->isActive());
        self::assertSame(2, $partner->getPosition());
        self::assertNull($partner->getLogoKey());
        self::assertFalse($partner->isLogoOnDark());
    }

    public function testALightLogoAsksForADarkChipAndAnEditCanTakeItBack(): void
    {
        $partner = Partner::create('p1', 'Minimapperz', '', 'https://minimapperz.example', true, 0, $this->now, logoOnDark: true);
        self::assertTrue($partner->isLogoOnDark());

        $partner->edit('Minimapperz', '', 'https://minimapperz.example', true, $this->now);
        self::assertFalse($partner->isLogoOnDark());
    }

    public function testAPartnerIsVisibleOnlyWhenShownWithALogo(): void
    {
        $partner = Partner::create('p1', 'Ludothèque', '', 'https://ludo.example', true, 0, $this->now);
        self::assertFalse($partner->isVisible());

        $partner->attachLogo('content-logos/p1/logo-abcd.png', $this->now);
        self::assertTrue($partner->isVisible());

        $partner->edit('Ludothèque', '', 'https://ludo.example', false, $this->now);
        self::assertFalse($partner->isVisible());

        $partner->edit('Ludothèque', '', 'https://ludo.example', true, $this->now);
        $partner->removeLogo($this->now);
        self::assertFalse($partner->isVisible());
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidFields(): iterable
    {
        yield 'empty name' => ['  ', '', 'https://ludo.example', 'partner_name_invalid'];
        yield 'name too long' => [str_repeat('a', 81), '', 'https://ludo.example', 'partner_name_invalid'];
        yield 'description too long' => ['Ludo', str_repeat('é', 251), 'https://ludo.example', 'partner_description_invalid'];
        yield 'bad url' => ['Ludo', '', 'http://ludo.example', 'partner_url_invalid'];
    }

    #[DataProvider('invalidFields')]
    public function testInvalidFieldsAreRefusedWithTheirCode(string $name, string $description, string $url, string $code): void
    {
        $this->expectExceptionObject(new \DomainException($code));

        Partner::create('p1', $name, $description, $url, true, 0, $this->now);
    }

    public function testTheBoundsThemselvesAreAccepted(): void
    {
        $partner = Partner::create('p1', str_repeat('a', 80), str_repeat('é', 250), 'https://ludo.example', true, 0, $this->now);

        self::assertSame(80, mb_strlen($partner->getName()));
        self::assertSame(250, mb_strlen($partner->getDescription()));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function urls(): iterable
    {
        yield 'an https address' => ['https://www.ludo.example/fr/accueil?ref=archilan', true];
        yield 'plain http' => ['http://ludo.example', false];
        yield 'a page of the site' => ['/evenements', false];
        yield 'protocol-relative' => ['//evil.example', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'with a space' => ['https://ludo .example', false];
        yield 'too long' => ['https://ludo.example/'.str_repeat('a', 480), false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('urls')]
    public function testOnlyHttpsAddressesAreAccepted(string $url, bool $accepted): void
    {
        self::assertSame($accepted, Partner::isAcceptedUrl($url));
    }

    public function testANegativePositionIsRefused(): void
    {
        $partner = Partner::create('p1', 'Ludo', '', 'https://ludo.example', true, 0, $this->now);
        $partner->moveTo(4, $this->now);
        self::assertSame(4, $partner->getPosition());

        $this->expectExceptionObject(new \DomainException('partner_position_invalid'));
        $partner->moveTo(-1, $this->now);
    }
}
