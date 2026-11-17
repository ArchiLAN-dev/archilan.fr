<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Domain\Entity\TrackedLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Story 55.1: what a tracked link accepts. */
final class TrackedLinkTest extends TestCase
{
    public function testAPathMakesAnInboundLinkAndAnHttpsAddressAnOutboundOne(): void
    {
        $now = new \DateTimeImmutable('2026-11-17T10:00:00+00:00');
        $inbound = TrackedLink::create('a', 'discord-nov', 'Discord novembre', '/evenements/archilan-4', $now);
        $outbound = TrackedLink::create('b', 'ludo', 'Ludothèque', 'https://ludo.example/jeux?x=1', $now);

        self::assertFalse($inbound->isOutbound());
        self::assertTrue($outbound->isOutbound());
        self::assertTrue($inbound->isActive());
    }

    public function testEditKeepsTheCodeAndTurnsTheLinkOff(): void
    {
        $now = new \DateTimeImmutable('2026-11-17T10:00:00+00:00');
        $link = TrackedLink::create('a', 'discord-nov', 'Discord', '/', $now);
        $later = $now->modify('+1 hour');

        $link->edit('  Discord de novembre ', 'https://discord.gg/x', false, $later);

        self::assertSame('discord-nov', $link->getCode());
        self::assertSame('Discord de novembre', $link->getLabel());
        self::assertSame('https://discord.gg/x', $link->getTarget());
        self::assertFalse($link->isActive());
        self::assertEquals($later, $link->getUpdatedAt());
    }

    #[DataProvider('invalidCodes')]
    public function testAnInvalidCodeIsRefused(string $code): void
    {
        $this->expectExceptionMessage('tracked_link_code_invalid');
        TrackedLink::create('a', $code, 'Lien', '/', new \DateTimeImmutable());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCodes(): iterable
    {
        yield 'too short' => ['ab'];
        yield 'too long' => [str_repeat('a', 41)];
        yield 'upper case' => ['Discord'];
        yield 'leading dash' => ['-discord'];
        yield 'trailing dash' => ['discord-'];
        yield 'slash' => ['a/b'];
        yield 'space' => ['a b'];
    }

    #[DataProvider('invalidTargets')]
    public function testAnInvalidTargetIsRefused(string $target): void
    {
        $this->expectExceptionMessage('tracked_link_target_invalid');
        TrackedLink::create('a', 'abc', 'Lien', $target, new \DateTimeImmutable());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTargets(): iterable
    {
        yield 'empty' => [''];
        yield 'protocol relative' => ['//evil.example'];
        yield 'backslash' => ['/\\evil.example'];
        yield 'http' => ['http://ludo.example'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'relative' => ['evenements'];
        yield 'no host' => ['https://'];
        yield 'space' => ['/a b'];
        yield 'too long' => ['/'.str_repeat('a', 500)];
    }

    public function testALabelIsRequired(): void
    {
        $this->expectExceptionMessage('tracked_link_label_invalid');
        TrackedLink::create('a', 'abc', '   ', '/', new \DateTimeImmutable());
    }
}
