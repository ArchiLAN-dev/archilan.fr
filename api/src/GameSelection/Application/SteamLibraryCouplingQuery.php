<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\SteamProfileReference;
use App\GameSelection\Infrastructure\SteamWebApiClientInterface;
use Psr\Log\LoggerInterface;

final readonly class SteamLibraryCouplingQuery
{
    public function __construct(
        private SteamWebApiClientInterface $steam,
        private SteamCatalogQueryInterface $catalog,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{outcome: 'ok'|'invalid_input'|'private_profile'|'steam_error', matchedGames: list<array{id: string, name: string, slug: string, coverImageUrl: string|null, availability: string, steamAppId: int}>, ownedCount: int, matchedCount: int}
     */
    public function couple(string $rawInput): array
    {
        $reference = SteamProfileReference::parse($rawInput);
        if (null === $reference) {
            return self::empty('invalid_input');
        }

        try {
            $steamId64 = SteamProfileReference::KIND_STEAMID64 === $reference->kind
                ? $reference->value
                : $this->steam->resolveVanityUrl($reference->value);

            if (null === $steamId64) {
                return self::empty('invalid_input');
            }

            $owned = $this->steam->fetchOwnedAppIds($steamId64);
        } catch (\Throwable $e) {
            $this->logger->warning('steam.coupling_failed', ['error' => $e->getMessage()]);

            return self::empty('steam_error');
        }

        if ('private' === $owned['visibility']) {
            return self::empty('private_profile');
        }

        $ownedSet = array_fill_keys($owned['appIds'], true);

        $matched = [];
        foreach ($this->catalog->allWithSteamAppId() as $game) {
            if (isset($ownedSet[$game['steamAppId']])) {
                $matched[] = $game;
            }
        }

        return [
            'outcome' => 'ok',
            'matchedGames' => $matched,
            'ownedCount' => count($owned['appIds']),
            'matchedCount' => count($matched),
        ];
    }

    /**
     * @param 'invalid_input'|'private_profile'|'steam_error' $outcome
     *
     * @return array{outcome: 'invalid_input'|'private_profile'|'steam_error', matchedGames: list<array{id: string, name: string, slug: string, coverImageUrl: string|null, availability: string, steamAppId: int}>, ownedCount: int, matchedCount: int}
     */
    private static function empty(string $outcome): array
    {
        return ['outcome' => $outcome, 'matchedGames' => [], 'ownedCount' => 0, 'matchedCount' => 0];
    }
}
