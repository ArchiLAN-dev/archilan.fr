<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Application\Command\RecordLinkVisit;
use App\Content\Domain\Entity\TrackedLink;
use App\Content\Domain\Repository\TrackedLinkRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Story 55.1: a pass through `/l/{code}`. */
final class RecordLinkVisitTest extends TestCase
{
    public function testAVisitCountsOnTheDayInParisWithTheCleanedHost(): void
    {
        $link = TrackedLink::create('link1', 'discord-nov', 'Discord', '/evenements/archilan-4', new \DateTimeImmutable());
        $links = $this->createMock(TrackedLinkRepositoryInterface::class);
        $links->method('findByCode')->willReturn($link);
        // 23:30 UTC on the 17th is already the 18th in Paris.
        $links->expects(self::once())->method('countVisit')->with('link1', '2026-11-18', 'discord.com');

        $target = new RecordLinkVisit($links, new MockClock('2026-11-17T23:30:00+00:00'))->visit('discord-nov', 'WWW.Discord.com', true);

        self::assertSame('/evenements/archilan-4', $target->target);
    }

    public function testAPassThatDoesNotCountOnlyGivesTheTarget(): void
    {
        $link = TrackedLink::create('link1', 'ludo', 'Ludo', 'https://ludo.example', new \DateTimeImmutable());
        $links = $this->createMock(TrackedLinkRepositoryInterface::class);
        $links->method('findByCode')->willReturn($link);
        $links->expects(self::never())->method('countVisit');

        $target = new RecordLinkVisit($links, new MockClock())->visit('ludo', '', false);

        self::assertSame('https://ludo.example', $target->target);
    }

    public function testATurnedOffLinkIsNotFound(): void
    {
        $now = new \DateTimeImmutable();
        $link = TrackedLink::create('link1', 'ludo', 'Ludo', '/', $now);
        $link->edit('Ludo', '/', false, $now);
        $links = $this->createMock(TrackedLinkRepositoryInterface::class);
        $links->method('findByCode')->willReturn($link);
        $links->expects(self::never())->method('countVisit');

        $this->expectException(NotFoundException::class);
        new RecordLinkVisit($links, new MockClock())->visit('ludo', '', true);
    }

    public function testAnInvalidCodeIsNotLookedUp(): void
    {
        $links = $this->createMock(TrackedLinkRepositoryInterface::class);
        $links->expects(self::never())->method('findByCode');

        $this->expectException(NotFoundException::class);
        new RecordLinkVisit($links, new MockClock())->visit('../admin', '', true);
    }

    public function testOnlyAPlainHostIsKept(): void
    {
        self::assertSame('t.co', RecordLinkVisit::host('t.co'));
        self::assertSame('archilan.fr', RecordLinkVisit::host(' www.ArchiLAN.fr '));
        self::assertSame('', RecordLinkVisit::host(''));
        self::assertSame('', RecordLinkVisit::host('evil.example/path'));
        self::assertSame('', RecordLinkVisit::host('<script>'));
        self::assertSame('', RecordLinkVisit::host('localhost:3000'));
        self::assertSame('', RecordLinkVisit::host(str_repeat('a', 254)));
    }
}
