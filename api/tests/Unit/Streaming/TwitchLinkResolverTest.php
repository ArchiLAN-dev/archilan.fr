<?php

declare(strict_types=1);

namespace App\Tests\Unit\Streaming;

use App\Streaming\Domain\TwitchLinkResolver;
use PHPUnit\Framework\TestCase;

final class TwitchLinkResolverTest extends TestCase
{
    public function testResolveLoginMatchesByLabel(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'Twitch', 'url' => 'https://twitch.tv/cooluser'],
        ]);

        self::assertSame('cooluser', $login);
    }

    public function testResolveLoginMatchesByHostWhenLabelIsOther(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'Mon stream', 'url' => 'https://www.twitch.tv/StreamerName'],
        ]);

        self::assertSame('streamername', $login);
    }

    public function testResolveLoginStripsTrailingSlash(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/foo/'],
        ]);

        self::assertSame('foo', $login);
    }

    public function testResolveLoginNormalisesUppercaseLogin(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/BigGamer_99'],
        ]);

        self::assertSame('biggamer_99', $login);
    }

    public function testResolveLoginStripsQueryString(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/foo?referrer=x'],
        ]);

        self::assertSame('foo', $login);
    }

    public function testResolveLoginAcceptsBareDomainWithoutScheme(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'Autre', 'url' => 'twitch.tv/barehandle'],
        ]);

        self::assertSame('barehandle', $login);
    }

    public function testResolveLoginAcceptsBareHandleWhenLabelIsTwitch(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'Twitch', 'url' => 'myhandle'],
        ]);

        self::assertSame('myhandle', $login);
    }

    public function testResolveLoginIgnoresNonTwitchLink(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'YouTube', 'url' => 'https://youtube.com/@someone'],
        ]);

        self::assertNull($login);
    }

    public function testResolveLoginIgnoresMalformedUrl(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'http://'],
        ]);

        self::assertNull($login);
    }

    public function testResolveLoginIgnoresLoginViolatingGrammar(): void
    {
        // Two chars is below the 3-char minimum.
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/ab'],
        ]);

        self::assertNull($login);
    }

    public function testResolveLoginReturnsFirstValidAmongMany(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'YouTube', 'url' => 'https://youtube.com/@x'],
            ['label' => 'Twitch', 'url' => 'https://twitch.tv/first'],
            ['label' => 'Twitch', 'url' => 'https://twitch.tv/second'],
        ]);

        self::assertSame('first', $login);
    }

    public function testResolveLoginReturnsNullForEmptyLinks(): void
    {
        self::assertNull(TwitchLinkResolver::resolveLogin([]));
    }

    public function testResolveLoginParsesReservedPathSegmentAsLogin(): void
    {
        // Out of scope to filter Twitch reserved paths - documented behaviour.
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/videos/12345'],
        ]);

        self::assertSame('videos', $login);
    }
}
