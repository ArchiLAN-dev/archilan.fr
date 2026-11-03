<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

/**
 * Story 49.1: the slug of a Minimapperz event, from what the admin pasted - the slug itself or the address of the
 * LAN (`https://app.minimapperz.com/lan/<slug>/`).
 */
final class MinimapperzSlug
{
    private const string SLUG_PATTERN = '/^[A-Za-z0-9_-]{1,100}$/';
    private const string LAN_URL_PATTERN = '#^https?://[^/]+/lan/([^/?\#]+)/?(?:[?\#].*)?$#i';

    /** @return string|null the slug, or null when the input is neither a slug nor a LAN address */
    public static function fromInput(string $input): ?string
    {
        $value = trim($input);
        if (1 === preg_match(self::LAN_URL_PATTERN, $value, $matches)) {
            $value = rawurldecode($matches[1]);
        }

        return 1 === preg_match(self::SLUG_PATTERN, $value) ? $value : null;
    }

    /** Story 49.2: where a player joins the LAN on Minimapperz. */
    public static function lanUrl(string $slug): string
    {
        return sprintf('https://app.minimapperz.com/lan/%s/', rawurlencode($slug));
    }
}
