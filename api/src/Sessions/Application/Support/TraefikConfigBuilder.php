<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;

final readonly class TraefikConfigBuilder
{
    /**
     * Port d'écoute du serveur Archipelago *à l'intérieur* de son conteneur. Il est constant : la
     * distinction entre deux runs se fait par le nom du conteneur, pas par ce port.
     */
    private const int AP_CONTAINER_PORT = 38281;

    /**
     * Préfixe des entrypoints Traefik dédiés aux serveurs Archipelago.
     *
     * CONTRAT AVEC L'INFRA : `scripts/gen-traefik-config.sh` (story 37.1) génère un entrypoint
     * `ap-{port}` par port de la plage. Changer la convention d'un côté sans l'autre produit des
     * routeurs qui référencent un entrypoint inexistant - Traefik les ignore en le journalisant,
     * et les runs deviennent silencieusement injoignables. Aucun test ne peut relier les deux :
     * ils vivent dans des couches différentes.
     */
    private const string ENTRYPOINT_PREFIX = 'ap-';

    public function __construct(
        private SessionRepositoryInterface $sessions,
        private string $publicHost,
    ) {
    }

    /**
     * Configuration Traefik (provider HTTP) pour toutes les sessions en cours.
     *
     * Un routeur TCP par session, sur l'entrypoint de son port. Le port identifie le run à lui
     * seul : il n'y a qu'un backend derrière chaque entrypoint, donc aucun démultiplexage par SNI
     * n'est nécessaire et la règle peut accepter n'importe quel SNI.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $sessions = $this->sessions->findByStatus(Session::STATUS_RUNNING);

        $routers = [];
        $services = [];

        foreach ($sessions as $session) {
            $port = $session->getPort();

            if (null === $port) {
                continue;
            }

            $key = 'run-'.$session->getId();

            $routers[$key] = [
                'rule' => 'HostSNI(`*`)',
                'service' => $key,
                'entryPoints' => [self::ENTRYPOINT_PREFIX.$port],
                // `domains` est obligatoire ici : avec un SNI joker, Traefik n'a aucun nom à
                // déduire de la règle et servirait son certificat par défaut, que tout navigateur
                // rejette sans interstitiel sur une connexion WebSocket.
                'tls' => [
                    'certResolver' => 'letsencrypt',
                    'domains' => [
                        ['main' => $this->publicHost],
                    ],
                ],
            ];

            $services[$key] = [
                'loadBalancer' => [
                    'servers' => [
                        // Adresse interne du conteneur, et non le port publié sur l'hôte : c'est
                        // ce qui permet à la story 37.3 de refermer cette publication.
                        ['address' => sprintf('ap-server-%s:%d', $session->getId(), self::AP_CONTAINER_PORT)],
                    ],
                ],
            ];
        }

        return [
            'tcp' => [
                // Objets vides et non tableaux vides : Traefik refuse la forme tableau.
                'routers' => $routers ?: new \stdClass(),
                'services' => $services ?: new \stdClass(),
            ],
        ];
    }
}
