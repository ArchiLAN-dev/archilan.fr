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
     * CONTRAT AVEC L'INFRA : `scripts/gen-traefik-entrypoints.sh` (story 37.1) génère un entrypoint
     * `ap-{port}` par port de la plage. Changer la convention d'un côté sans l'autre produit des
     * routeurs qui référencent un entrypoint inexistant - Traefik les ignore en le journalisant,
     * et les runs deviennent silencieusement injoignables. Aucun test ne peut relier les deux :
     * ils vivent dans des couches différentes.
     */
    private const string ENTRYPOINT_PREFIX = 'ap-';

    /**
     * Préfixes des deux routeurs d'une même session : le chiffré et le clair.
     *
     * Deux **préfixes** et non un préfixe et un suffixe, parce qu'un suffixe rend une collision
     * possible : la clé claire de la session `x` vaudrait `run-x-plain`, soit exactement la clé
     * chiffrée d'une session dont l'identifiant serait `x-plain`. Ici les deux familles commencent
     * par des caractères différents, donc aucune clé de l'une ne peut valoir une clé de l'autre,
     * quel que soit le contenu des identifiants. Ne pas « harmoniser » en suffixe.
     */
    private const string ROUTER_PREFIX_TLS = 'run-';
    private const string ROUTER_PREFIX_PLAIN = 'plain-';

    public function __construct(
        private SessionRepositoryInterface $sessions,
        private string $publicHost,
        /**
         * Nom du certresolver **tel qu'il est déclaré dans le proxy**, qui n'est pas versionné ici
         * et sert plusieurs projets. Un nom inconnu de Traefik ne provoque aucune erreur visible :
         * il sert son certificat par défaut, et le navigateur refuse la connexion WebSocket sans
         * interstitiel. D'où une valeur configurable plutôt qu'une constante optimiste.
         */
        private string $certResolver,
    ) {
    }

    /**
     * Configuration Traefik (provider HTTP) pour toutes les sessions en cours.
     *
     * **Deux routeurs TCP par session, sur le même entrypoint et vers le même service** : l'un
     * chiffré, l'autre en clair. Traefik inspecte les premiers octets d'une connexion et l'oriente
     * vers son muxer TLS s'il reconnaît un ClientHello, vers son muxer non-TLS sinon - donc chaque
     * schéma trouve son routeur sans qu'on ait à écrire la moindre détection. Mesuré sur
     * `traefik:v3.6.25` (story 37.8) : les deux routeurs cohabitent sans avertissement, et un
     * upgrade WebSocket aboutit aussi bien en `ws://` qu'en `wss://`.
     *
     * Le chemin en clair existe pour les clients Archipelago qui ne savent pas parler TLS,
     * notamment des mods de jeu embarquant leur propre client. Le supprimer les exclut des runs
     * sans message compréhensible : ils reçoivent un HTTP 404, Traefik ne trouvant alors aucun
     * routeur non-TLS et repassant la connexion à son handler HTTP par défaut. C'est exactement la
     * régression qu'a produite la story 37.3, du 2026-08-14 au correctif de la story 37.8.
     *
     * Le port identifie le run à lui seul : il n'y a qu'un backend derrière chaque entrypoint, donc
     * aucun démultiplexage par SNI n'est nécessaire et les règles acceptent n'importe quel SNI.
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

            $key = self::ROUTER_PREFIX_TLS.$session->getId();
            $entryPoints = [self::ENTRYPOINT_PREFIX.$port];

            $routers[$key] = [
                'rule' => 'HostSNI(`*`)',
                'service' => $key,
                'entryPoints' => $entryPoints,
                // `domains` est obligatoire ici : avec un SNI joker, Traefik n'a aucun nom à
                // déduire de la règle et servirait son certificat par défaut, que tout navigateur
                // rejette sans interstitiel sur une connexion WebSocket.
                'tls' => [
                    'certResolver' => $this->certResolver,
                    'domains' => [
                        ['main' => $this->publicHost],
                    ],
                ],
            ];

            // Aucun bloc `tls` : c'est sa seule absence qui range ce routeur dans le muxer non-TLS.
            // Il partage le service du routeur chiffré - un seul backend, deux portes d'entrée.
            $routers[self::ROUTER_PREFIX_PLAIN.$session->getId()] = [
                'rule' => 'HostSNI(`*`)',
                'service' => $key,
                'entryPoints' => $entryPoints,
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
