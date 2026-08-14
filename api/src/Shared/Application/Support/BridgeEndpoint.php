<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

/**
 * Adresse interne du bridge d'une session.
 *
 * Le bridge tourne dans un conteneur nommé, sur le même réseau Docker que l'API. Le joindre par son
 * nom évite de sortir vers l'adresse publique du serveur pour atteindre un voisin, et permet de
 * cesser de publier son port sur l'hôte (story 37.7).
 *
 * CONTRAT AVEC L'ORCHESTRATEUR : c'est lui qui nomme le conteneur, dans
 * `internal/docker/client.go`. Changer la convention d'un côté sans l'autre rend le bridge
 * injoignable ; aucun test ne peut relier les deux, ils vivent dans deux dépôts.
 */
final class BridgeEndpoint
{
    /**
     * Port d'écoute **à l'intérieur** du conteneur. Constant : c'est le nom du conteneur qui
     * distingue deux sessions, pas ce port. Le port hôte alloué à la session ne sert plus
     * d'adresse - il reste le marqueur qu'un bridge a bien été lancé.
     */
    private const int CONTAINER_PORT = 5000;

    private const string CONTAINER_PREFIX = 'archilan-bridge-';

    public static function baseUrl(string $sessionId): string
    {
        return sprintf('http://%s%s:%d', self::CONTAINER_PREFIX, $sessionId, self::CONTAINER_PORT);
    }

    /**
     * @param string $path chemin absolu, barre oblique comprise (`/state`, `/hints/3`...)
     */
    public static function url(string $sessionId, string $path): string
    {
        return self::baseUrl($sessionId).$path;
    }
}
