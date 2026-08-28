<?php

declare(strict_types=1);

namespace App\Streaming\Application\Support;

use App\Streaming\Application\Port\TwitchApiClientInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Qui diffuse en ce moment, parmi un ensemble de logins Twitch (story 30.39).
 *
 * Extrait de `ParticipantStreamsView`, qui portait déjà cette logique pour les streams d'une partie,
 * afin que la page communauté la partage au lieu d'en écrire une proche mais différente. Deux caches
 * voisins finissent toujours par diverger sur la durée de vie ou sur le traitement des pannes.
 *
 * Le cache est clé par l'**ensemble trié** des logins : plusieurs pages qui regardent les mêmes
 * streamers réutilisent un seul appel Helix pendant la durée de vie. Une panne Twitch (le client rend
 * `null`) est mémorisée comme « personne en direct » pendant 15 s seulement, pour qu'un incident
 * passager se répare vite au lieu de figer tout le monde hors ligne pendant les 60 s réservées à une
 * réponse fiable (report de la story 7.7, résolu en 33.8).
 */
final readonly class LiveTwitchLogins
{
    /** Durée de vie d'une réponse fiable. */
    private const int TTL_SECONDS = 60;

    /** Durée de vie d'une panne : courte, pour que l'incident se répare de lui-même. */
    private const int OUTAGE_TTL_SECONDS = 15;

    public function __construct(
        private TwitchApiClientInterface $client,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @param list<string> $logins
     *
     * @return array<string, int> login en direct => nombre de spectateurs
     */
    public function among(array $logins): array
    {
        $unique = array_values(array_unique($logins));
        if ([] === $unique) {
            return [];
        }

        sort($unique);
        $key = 'streaming.live_logins.'.md5(implode(',', $unique));

        return $this->cache->get($key, function (ItemInterface $item) use ($unique): array {
            $result = $this->client->fetchLiveLogins($unique);

            if (null === $result) {
                $item->expiresAfter(self::OUTAGE_TTL_SECONDS);

                return [];
            }

            $item->expiresAfter(self::TTL_SECONDS);

            return $result;
        });
    }
}
