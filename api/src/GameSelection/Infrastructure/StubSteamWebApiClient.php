<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

final class StubSteamWebApiClient implements SteamWebApiClientInterface
{
    /** @var array<string, string> vanity name => SteamID64 */
    public static array $vanityMap = [];

    /** @var list<int> */
    public static array $ownedAppIds = [];

    public static string $visibility = 'public';

    public static bool $fails = false;

    public static function reset(): void
    {
        self::$vanityMap = [];
        self::$ownedAppIds = [];
        self::$visibility = 'public';
        self::$fails = false;
    }

    public function resolveVanityUrl(string $vanity): ?string
    {
        if (self::$fails) {
            throw new SteamApiException('Stubbed Steam failure');
        }

        return self::$vanityMap[$vanity] ?? null;
    }

    public function fetchOwnedAppIds(string $steamId64): array
    {
        if (self::$fails) {
            throw new SteamApiException('Stubbed Steam failure');
        }

        if ('private' === self::$visibility) {
            return ['visibility' => 'private', 'appIds' => []];
        }

        return ['visibility' => 'public', 'appIds' => self::$ownedAppIds];
    }
}
