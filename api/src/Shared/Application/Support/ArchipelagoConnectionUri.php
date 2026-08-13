<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

/**
 * Adresse de connexion chiffrée d'un serveur Archipelago.
 *
 * Une page servie en HTTPS ne peut pas ouvrir un WebSocket en clair : sans cette forme, aucun
 * client web ne peut joindre une run (epic 37).
 *
 * Dérivée à la lecture, jamais stockée : une run relancée change de port, et une adresse persistée
 * survivrait à ce changement sans que rien ne le signale.
 */
final class ArchipelagoConnectionUri
{
    private const string SCHEME = 'wss';

    public static function build(string $host, int $port): string
    {
        return sprintf('%s://%s:%d', self::SCHEME, $host, $port);
    }

    /**
     * Variante tolérante pour les lectures : une session qui n'est pas en cours n'a ni hôte ni
     * port, et n'a donc pas d'adresse - ce n'est pas une erreur.
     */
    public static function tryBuild(?string $host, ?int $port): ?string
    {
        if (null === $host || '' === trim($host) || null === $port || $port <= 0) {
            return null;
        }

        return self::build($host, $port);
    }
}
