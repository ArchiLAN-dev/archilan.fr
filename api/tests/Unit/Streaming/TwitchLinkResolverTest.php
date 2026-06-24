<?php

declare(strict_types=1);

namespace App\Tests\Unit\Streaming;

use App\Streaming\Domain\TwitchLinkResolver;
use PHPUnit\Framework\TestCase;

final class TwitchLinkResolverTest extends TestCase
{
    public function testResolveLogin_matchesByLabel(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'Twitch', 'url' => 'https://twitch.tv/cooluser'],
        ]);

        self::assertSame('cooluser', $login);
    }

    public function testResolveLogin_matchesByHostWhenLabelIsOther(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'Mon stream', 'url' => 'https://www.twitch.tv/StreamerName'],
        ]);

        self::assertSame('streamername', $login);
    }

    public function testResolveLogin_stripsTrailingSlash(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/foo/'],
        ]);

        self::assertSame('foo', $login);
    }

    public function testResolveLogin_normalisesUppercaseLogin(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/BigGamer_99'],
        ]);

        self::assertSame('biggamer_99', $login);
    }

    public function testResolveLogin_stripsQueryString(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/foo?referrer=x'],
        ]);

        self::assertSame('foo', $login);
    }

    public function testResolveLogin_acceptsBareDomainWithoutScheme(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'Autre', 'url' => 'twitch.tv/barehandle'],
        ]);

        self::assertSame('barehandle', $login);
    }

    public function testResolveLogin_acceptsBareHandleWhenLabelIsTwitch(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'Twitch', 'url' => 'myhandle'],
        ]);

        self::assertSame('myhandle', $login);
    }

    public function testResolveLogin_ignoresNonTwitchLink(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'YouTube', 'url' => 'https://youtube.com/@someone'],
        ]);

        self::assertNull($login);
    }

    public function testResolveLogin_ignoresMalformedUrl(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'http://'],
        ]);

        self::assertNull($login);
    }

    public function testResolveLogin_ignoresLoginViolatingGrammar(): void
    {
        // Two chars is below the 3-char minimum.
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/ab'],
        ]);

        self::assertNull($login);
    }

    public function testResolveLogin_returnsFirstValidAmongMany(): void
    {
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'YouTube', 'url' => 'https://youtube.com/@x'],
            ['label' => 'Twitch', 'url' => 'https://twitch.tv/first'],
            ['label' => 'Twitch', 'url' => 'https://twitch.tv/second'],
        ]);

        self::assertSame('first', $login);
    }

    public function testResolveLogin_returnsNullForEmptyLinks(): void
    {
        self::assertNull(TwitchLinkResolver::resolveLogin([]));
    }

    public function testResolveLogin_parsesReservedPathSegmentAsLogin(): void
    {
        // Out of scope to filter Twitch reserved paths - documented behaviour.
        $login = TwitchLinkResolver::resolveLogin([
            ['label' => 'twitch', 'url' => 'https://twitch.tv/videos/12345'],
        ]);

        self::assertSame('videos', $login);
    }
}