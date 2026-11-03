<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Support;

use App\Identity\Application\Support\DiscordStateToken;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The browser half of the Discord OAuth `state` (story 50.3). Scoped to the callback that reads it, so each flow
 * keeps its own, and cleared there whatever the outcome: a nonce serves one round trip. SameSite=Lax is what lets it
 * come back on the top-level redirect from Discord.
 */
final class DiscordStateCookie
{
    private const string NAME = '__Secure-archilan_discord_state';

    public static function issue(string $nonce, string $callbackPath): Cookie
    {
        return Cookie::create(self::NAME)
            ->withValue($nonce)
            ->withExpires(time() + DiscordStateToken::MAX_AGE_SECONDS)
            ->withPath($callbackPath)
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }

    public static function read(Request $request): string
    {
        $nonce = $request->cookies->get(self::NAME);

        return is_string($nonce) ? $nonce : '';
    }

    public static function clear(Response $response, string $callbackPath): void
    {
        $response->headers->clearCookie(self::NAME, $callbackPath, null, true, true, Cookie::SAMESITE_LAX);
    }
}
