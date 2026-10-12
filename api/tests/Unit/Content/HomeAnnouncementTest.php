<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Domain\Entity\HomeAnnouncement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Story 10.14: an announcement opened as a modal on the home page between two dates. */
final class HomeAnnouncementTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-12T12:00:00+02:00');
    }

    public function testTheStatusFollowsTheWindowToTheSecond(): void
    {
        $announcement = $this->announcement('2026-10-19T00:01:00+02:00', '2026-10-31T23:59:00+01:00');

        self::assertSame(HomeAnnouncement::STATUS_UPCOMING, $announcement->statusAt(new \DateTimeImmutable('2026-10-19T00:00:59+02:00')));
        self::assertSame(HomeAnnouncement::STATUS_RUNNING, $announcement->statusAt(new \DateTimeImmutable('2026-10-19T00:01:00+02:00')));
        self::assertSame(HomeAnnouncement::STATUS_RUNNING, $announcement->statusAt(new \DateTimeImmutable('2026-10-31T23:58:59+01:00')));
        self::assertSame(HomeAnnouncement::STATUS_ENDED, $announcement->statusAt(new \DateTimeImmutable('2026-10-31T23:59:00+01:00')));
    }

    public function testWindowsThatOnlyTouchDoNotOverlap(): void
    {
        $announcement = $this->announcement('2026-10-19T00:00:00+02:00', '2026-10-25T00:00:00+02:00');

        self::assertFalse($announcement->overlaps(new \DateTimeImmutable('2026-10-25T00:00:00+02:00'), new \DateTimeImmutable('2026-10-30T00:00:00+01:00')));
        self::assertTrue($announcement->overlaps(new \DateTimeImmutable('2026-10-24T23:00:00+02:00'), new \DateTimeImmutable('2026-10-30T00:00:00+01:00')));
        self::assertTrue($announcement->overlaps(new \DateTimeImmutable('2026-10-01T00:00:00+02:00'), new \DateTimeImmutable('2026-11-30T00:00:00+01:00')));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function links(): iterable
    {
        yield 'a page of the site' => ['/evenements/archilan-4', true];
        yield 'an https address' => ['https://www.helloasso.com/associations/archilan', true];
        yield 'protocol-relative' => ['//evil.example', false];
        yield 'backslash trick' => ['/\\evil.example', false];
        yield 'plain http' => ['http://archilan.fr', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'with a space' => ['/evenements/archi lan', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('links')]
    public function testOnlyPagesOfTheSiteAndHttpsAddressesAreAccepted(string $url, bool $accepted): void
    {
        self::assertSame($accepted, HomeAnnouncement::isAcceptedLink($url));
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function invalidFields(): iterable
    {
        yield 'no title' => ['  ', '', 'Go', '/x', '2026-10-19T00:00:00+02:00', 'home_announcement_title_invalid'];
        yield 'no button label' => ['Titre', '', ' ', '/x', '2026-10-19T00:00:00+02:00', 'home_announcement_cta_label_invalid'];
        yield 'a bad link' => ['Titre', '', 'Go', 'ftp://x', '2026-10-19T00:00:00+02:00', 'home_announcement_cta_url_invalid'];
        yield 'a text too long' => ['Titre', str_repeat('a', HomeAnnouncement::MAX_BODY + 1), 'Go', '/x', '2026-10-19T00:00:00+02:00', 'home_announcement_body_invalid'];
        yield 'the end before the start' => ['Titre', '', 'Go', '/x', '2026-11-30T00:00:00+01:00', 'home_announcement_window_invalid'];
    }

    #[DataProvider('invalidFields')]
    public function testAnInvalidFieldIsRefusedWithItsCode(string $title, string $body, string $label, string $url, string $startsAt, string $code): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage($code);

        HomeAnnouncement::create('a1', $title, $body, $label, $url, new \DateTimeImmutable($startsAt), new \DateTimeImmutable('2026-10-31T23:59:00+01:00'), $this->now);
    }

    public function testAnAnnouncementCannotEndInThePast(): void
    {
        $this->expectExceptionMessage('home_announcement_window_invalid');

        HomeAnnouncement::create('a1', 'Titre', '', 'Go', '/x', new \DateTimeImmutable('2026-10-01T00:00:00+02:00'), new \DateTimeImmutable('2026-10-10T00:00:00+02:00'), $this->now);
    }

    public function testFieldsAreTrimmed(): void
    {
        $announcement = HomeAnnouncement::create('a1', '  Inscriptions  ', ' Texte ', ' Go ', ' /x ', new \DateTimeImmutable('2026-10-19T00:01:00+02:00'), new \DateTimeImmutable('2026-10-31T23:59:00+01:00'), $this->now);

        self::assertSame('Inscriptions', $announcement->getTitle());
        self::assertSame('Texte', $announcement->getBody());
        self::assertSame('Go', $announcement->getCtaLabel());
        self::assertSame('/x', $announcement->getCtaUrl());
    }

    private function announcement(string $startsAt, string $endsAt): HomeAnnouncement
    {
        return HomeAnnouncement::create('a1', 'Inscriptions ArchiLAN 4', '', "S'inscrire", '/evenements', new \DateTimeImmutable($startsAt), new \DateTimeImmutable($endsAt), $this->now);
    }
}
